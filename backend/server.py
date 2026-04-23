"""
CoreFlux AI Sidecar

Stateless FastAPI service that fronts all LLM calls for the PHP core.
No persistence here — PHP owns the audit log. This service:
  1. Receives a typed prompt + feature_class from PHP
  2. Routes to the right OpenAI model (configurable per feature class)
  3. Enforces a strict response envelope (no raw values the app can calc with)
  4. Returns JSON envelope back to PHP

PHP is the ONLY caller. We restrict to server-to-server via a shared secret.
"""

import os
import json
import hashlib
import time
from typing import Literal, Optional, List
from pathlib import Path

from fastapi import FastAPI, HTTPException, Header, Request
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field, field_validator
from dotenv import load_dotenv
from openai import AsyncOpenAI, OpenAIError

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
ROOT = Path(__file__).parent
load_dotenv(ROOT / ".env")

OPENAI_API_KEY = os.environ.get("OPENAI_API_KEY")
AI_SIDECAR_SECRET = os.environ.get("AI_SIDECAR_SECRET", "dev-only-replace-me")

# Per feature-class model map (override in .env)
FEATURE_MODELS = {
    "summary":        os.environ.get("AI_MODEL_SUMMARY",        "gpt-5.4-mini"),
    "narrative":      os.environ.get("AI_MODEL_NARRATIVE",      "gpt-5.4"),
    "draft":          os.environ.get("AI_MODEL_DRAFT",          "gpt-5.4"),
    "classification": os.environ.get("AI_MODEL_CLASSIFICATION", "gpt-5.4-mini"),
    "deep_reasoning": os.environ.get("AI_MODEL_DEEP_REASONING", "gpt-5.4-thinking"),
}

FALLBACK_MODEL = os.environ.get("AI_FALLBACK_MODEL", "gpt-5.2")

openai_client: Optional[AsyncOpenAI] = None
if OPENAI_API_KEY:
    openai_client = AsyncOpenAI(api_key=OPENAI_API_KEY)

# ---------------------------------------------------------------------------
# App
# ---------------------------------------------------------------------------
app = FastAPI(title="CoreFlux AI Sidecar", version="0.1.0")

# Only PHP core should call this. Locked down CORS; server-to-server.
app.add_middleware(
    CORSMiddleware,
    allow_origins=[],           # no browser origin allowed
    allow_credentials=False,
    allow_methods=["POST", "GET"],
    allow_headers=["*"],
)

# ---------------------------------------------------------------------------
# Models — request/response contract
# ---------------------------------------------------------------------------
FeatureClass = Literal["summary", "narrative", "draft", "classification", "deep_reasoning"]
ResponseKind = Literal["narrative", "summary", "suggestion", "classification", "question"]

# Keys the LLM is FORBIDDEN from emitting (business-logic consumables).
FORBIDDEN_KEYS = {
    "value", "amount", "total", "rate", "percentage",
    "formula", "calc", "calculation", "result",
    "decision", "next_step", "action", "execute",
    "number", "figure",
}


class Citation(BaseModel):
    source: str                            # "payroll.paystubs#id=42" or "uploaded:filename.pdf"
    excerpt: Optional[str] = None          # short text quote from the source


class AIRequest(BaseModel):
    feature_class: FeatureClass
    kind: ResponseKind                     # what shape the caller expects
    system: Optional[str] = None           # system-prompt override (the module's domain context)
    prompt: str = Field(..., min_length=1, max_length=20_000)
    context: Optional[dict] = None         # deterministic data the model may REFERENCE but not re-output as values
    citations: Optional[List[Citation]] = None
    max_output_tokens: int = Field(default=800, ge=1, le=8000)
    session_id: Optional[str] = None


class AIResponse(BaseModel):
    kind: ResponseKind
    content: str                           # natural-language text — the ONLY field the app may display
    confidence: Optional[float] = None     # 0..1, display-only
    citations: Optional[List[Citation]] = None
    requires_human_review: bool = True     # hard-coded true by contract
    model: str                             # which model actually ran
    latency_ms: int
    prompt_hash: str                       # sha256 of the prompt (for audit on PHP side)
    response_hash: str                     # sha256 of content


# ---------------------------------------------------------------------------
# Prompt shaping — forces the model to stay in "advisory narrative" mode
# ---------------------------------------------------------------------------
GUARDRAIL_SYSTEM = (
    "You are a business narrative assistant embedded in CoreFlux, a multi-tenant ERP.\n"
    "HARD RULES — violating any of these is a critical failure:\n"
    "1. You NEVER output numbers the application could use in a calculation. "
    "If you reference a number from the provided context, wrap it in natural language and "
    "cite it — do not present it as a raw value the system could parse.\n"
    "2. You NEVER output formulas, decisions, or tasks the system should auto-execute. "
    "All of your output is advisory for a human reader.\n"
    "3. You NEVER output JSON, code, or structured data unless the caller explicitly asks "
    "for a classification label.\n"
    "4. If asked to produce a value, formula, or decision, refuse and explain that the "
    "application's deterministic logic must produce it.\n"
    "5. Everything you produce will be reviewed by a human before any system uses it.\n"
)


def shape_system(kind: ResponseKind, caller_system: Optional[str]) -> str:
    kind_hint = {
        "narrative":      "Produce a short natural-language narrative. 1–3 short paragraphs.",
        "summary":        "Produce a concise bulleted summary. 3–6 bullets, each one sentence.",
        "suggestion":     "Produce a draft suggestion a human will edit and approve. Plain prose.",
        "classification": "Return a single short label followed by a one-sentence rationale. Format: 'LABEL — rationale'.",
        "question":       "Produce a clarifying question for the human user. One sentence.",
    }[kind]
    domain = f"\n\nDomain context from the calling module:\n{caller_system}" if caller_system else ""
    return f"{GUARDRAIL_SYSTEM}\n{kind_hint}{domain}"


def contains_forbidden_structure(text: str) -> Optional[str]:
    """
    Cheap guardrail: reject responses that look like the model tried to emit
    structured values (bare JSON objects with forbidden keys).
    """
    stripped = text.strip()
    if stripped.startswith("{") and stripped.endswith("}"):
        try:
            obj = json.loads(stripped)
            if isinstance(obj, dict):
                bad = FORBIDDEN_KEYS & {k.lower() for k in obj.keys()}
                if bad:
                    return f"response contained forbidden keys: {sorted(bad)}"
        except json.JSONDecodeError:
            pass
    return None


def sha(s: str) -> str:
    return hashlib.sha256(s.encode("utf-8")).hexdigest()


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------
@app.get("/api/ai/health")
async def health():
    return {
        "status":          "ok",
        "openai_key_set":  bool(OPENAI_API_KEY),
        "feature_models":  FEATURE_MODELS,
        "fallback_model":  FALLBACK_MODEL,
    }


@app.post("/api/ai/chat", response_model=AIResponse)
async def chat(req: AIRequest, x_ai_secret: Optional[str] = Header(default=None)):
    # Server-to-server auth only
    if x_ai_secret != AI_SIDECAR_SECRET:
        raise HTTPException(status_code=401, detail="bad or missing X-AI-Secret")
    if not openai_client:
        raise HTTPException(status_code=503, detail="OPENAI_API_KEY not configured")

    model = FEATURE_MODELS.get(req.feature_class, FALLBACK_MODEL)
    system_prompt = shape_system(req.kind, req.system)

    # Context travels as a sidecar message so the LLM can SEE but not re-emit raw values.
    user_parts = [req.prompt]
    if req.context:
        user_parts.append(
            "\n\n[context data — for reference only; do NOT restate numeric values as raw figures]\n"
            + json.dumps(req.context, ensure_ascii=False)[:8000]
        )
    if req.citations:
        user_parts.append(
            "\n\n[known citations you may reference by source id]\n"
            + "\n".join(f"- {c.source}" + (f": {c.excerpt}" if c.excerpt else "") for c in req.citations)
        )
    user_text = "".join(user_parts)

    started = time.perf_counter()
    used_model = model
    try:
        completion = await openai_client.chat.completions.create(
            model=model,
            messages=[
                {"role": "system", "content": system_prompt},
                {"role": "user",   "content": user_text},
            ],
            max_completion_tokens=req.max_output_tokens,
        )
    except OpenAIError as e:
        # Automatic fallback to a known-good model (only once)
        if model != FALLBACK_MODEL:
            used_model = FALLBACK_MODEL
            try:
                completion = await openai_client.chat.completions.create(
                    model=FALLBACK_MODEL,
                    messages=[
                        {"role": "system", "content": system_prompt},
                        {"role": "user",   "content": user_text},
                    ],
                    max_completion_tokens=req.max_output_tokens,
                )
            except OpenAIError as e2:
                raise HTTPException(status_code=502, detail=f"OpenAI error: {e2}") from e2
        else:
            raise HTTPException(status_code=502, detail=f"OpenAI error: {e}") from e

    latency_ms = int((time.perf_counter() - started) * 1000)
    text = (completion.choices[0].message.content or "").strip()

    violation = contains_forbidden_structure(text)
    if violation:
        raise HTTPException(status_code=422, detail=f"model violated response contract: {violation}")

    return AIResponse(
        kind=req.kind,
        content=text,
        confidence=None,  # OpenAI doesn't return calibrated confidence; left null by design
        citations=req.citations,
        requires_human_review=True,
        model=used_model,
        latency_ms=latency_ms,
        prompt_hash=sha(req.prompt + (req.system or "")),
        response_hash=sha(text),
    )
