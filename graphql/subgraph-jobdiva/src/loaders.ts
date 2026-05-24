/**
 * DataLoaders for JobDiva entities.
 *
 * Why
 * ---
 * Without batching, every Placement in a list query that selects
 * `jobDiva.job.title` would fire one /searchJob per placement. DataLoader
 * coalesces all jobIds in a single tick into ONE batched fetch.
 *
 * JobDiva's `searchJob` accepts a single `jobId` per call (not a bulk
 * array), so "batching" here means "dedupe and fetch in parallel inside
 * a request scope". This still wins vs. N sequential round-trips because
 * the resolver tree visits N entities concurrently.
 *
 * Per-request — never cache across requests. Tenants must not see each
 * other's enriched payloads.
 */
import DataLoader from 'dataloader';
import { firstRow, jobdivaCall } from './client.js';

interface LoaderCtx {
  tenantId: number;
}

interface BrokenSet { [endpoint: string]: boolean }

/** Build the JobDiva endpoint config for a single entity kind. */
interface EntityCfg {
  endpoint: string;
  bodyKey:  string;
}

const CONFIGS: Record<string, EntityCfg> = {
  job:       { endpoint: '/apiv2/jobdiva/searchJob',       bodyKey: 'jobId' },
  candidate: { endpoint: '/apiv2/jobdiva/searchCandidate', bodyKey: 'candidateId' },
  customer:  { endpoint: '/apiv2/jobdiva/searchCustomer',  bodyKey: 'customerId' },
  contact:   { endpoint: '/apiv2/jobdiva/searchContact',   bodyKey: 'contactId' },
  start:     { endpoint: '/apiv2/jobdiva/searchStart',     bodyKey: 'startId' },
};

/**
 * Build a loader for one entity kind. `broken` is shared per-request so
 * the FIRST 4xx on /searchJob trips the rest of the batch to null fast,
 * matching the PHP enricher's behaviour.
 */
function makeKindLoader(ctx: LoaderCtx, kind: keyof typeof CONFIGS, broken: BrokenSet) {
  const cfg = CONFIGS[kind];
  return new DataLoader<string | number, Record<string, any> | null>(
    async (ids) => {
      // We fetch in parallel; each /searchX call is independent.
      return Promise.all(
        ids.map(async (id) => {
          const numId = Number(id);
          if (!Number.isFinite(numId) || numId <= 0) return null;
          if (broken[cfg.endpoint]) return null;
          try {
            const resp = await jobdivaCall({
              tenantId: ctx.tenantId,
              method:   'POST',
              path:     cfg.endpoint,
              body:     { [cfg.bodyKey]: numId },
            });
            return firstRow(resp);
          } catch (e: any) {
            const status = e?.httpStatus ?? 0;
            if (status >= 400 && status < 500) {
              broken[cfg.endpoint] = true;
            }
            return null;
          }
        })
      );
    },
    { cache: true }  // Per-request cache — discarded after the GraphQL op completes.
  );
}

export interface JobDivaLoaders {
  job:        DataLoader<string | number, Record<string, any> | null>;
  candidate:  DataLoader<string | number, Record<string, any> | null>;
  customer:   DataLoader<string | number, Record<string, any> | null>;
  contact:    DataLoader<string | number, Record<string, any> | null>;
  start:      DataLoader<string | number, Record<string, any> | null>;
}

export function buildJobDivaLoaders(ctx: LoaderCtx): JobDivaLoaders {
  const broken: BrokenSet = {};
  return {
    job:       makeKindLoader(ctx, 'job', broken),
    candidate: makeKindLoader(ctx, 'candidate', broken),
    customer:  makeKindLoader(ctx, 'customer', broken),
    contact:   makeKindLoader(ctx, 'contact', broken),
    start:     makeKindLoader(ctx, 'start', broken),
  };
}
