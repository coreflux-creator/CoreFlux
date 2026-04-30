<?php
/**
 * M365GraphDriver — Microsoft 365 / Graph API implementation of MailDriver.
 *
 * Phase B Slice 2a scope: OAuth (delegated, multi-tenant) + delta-query
 * inbox polling + minimal message + attachment fetch. Outbound send is
 * deliberately NOT implemented here — outbound email is handled by
 * Core\Mail\ResendDriver platform-wide.
 *
 * Configuration via env:
 *   MICROSOFT_CLIENT_ID      = Azure AD app client_id (multi-tenant)
 *   MICROSOFT_CLIENT_SECRET  = client secret VALUE from Azure Portal
 *   MICROSOFT_REDIRECT_URI   = redirect URI registered in Azure
 *
 * Scopes (delegated): Mail.Read offline_access
 *
 * Token storage: VARBINARY ciphertext via Core\encryptField in
 * tenant_mail_connections. Delta cursor stored in
 * tenant_mail_folders.last_message_cursor (deltatoken extracted from
 * @odata.deltaLink — full URL is too long for the column).
 *
 * SPEC: /app/core/MailService.SPEC.md (skinny 3b → Phase B Slice 2a)
 */

namespace Core\Mail;

require_once __DIR__ . '/MailDriver.php';

class M365GraphDriver implements MailDriver
{
    public  const AUTHZ_URL   = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    public  const TOKEN_URL   = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    public  const GRAPH_BASE  = 'https://graph.microsoft.com/v1.0';
    public  const SCOPES      = 'https://graph.microsoft.com/Mail.Read offline_access';

    private string  $clientId;
    private string  $clientSecret;
    private string  $redirectUri;

    /** @var (callable(array):array)|null Optional test transport for HTTP. */
    private $transport;

    public function __construct(
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $redirectUri = null,
        ?callable $transport = null
    ) {
        $this->clientId     = $clientId     ?? (string) getenv('MICROSOFT_CLIENT_ID');
        $this->clientSecret = $clientSecret ?? (string) getenv('MICROSOFT_CLIENT_SECRET');
        $this->redirectUri  = $redirectUri  ?? (string) getenv('MICROSOFT_REDIRECT_URI');
        $this->transport    = $transport;
    }

    public function driver_name(): string { return 'm365'; }

    /** Outbound is delegated to Resend — drivers implementing only inbound just return failed. */
    public function send(array $envelope): array
    {
        return [
            'provider_message_id' => null,
            'sent_at'             => null,
            'status'              => 'failed',
            'error'               => 'M365GraphDriver does not send outbound mail (use Resend driver).',
        ];
    }

    public function refresh_oauth(int $connectionId): void
    {
        $row = $this->loadConnection($connectionId);
        if (!$row) throw new \RuntimeException("connection {$connectionId} not found");

        $refresh = decryptField($row['oauth_refresh_token_ct'] ?? null);
        if (!$refresh) throw new \RuntimeException("refresh_token missing for connection {$connectionId}");

        $resp = $this->httpPost(self::TOKEN_URL, [], http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'scope'         => self::SCOPES,
        ]), 'application/x-www-form-urlencoded');

        if (!($resp['ok'] ?? false)) {
            $this->markStatus($connectionId, 'reauth_required', $resp['error'] ?? 'refresh failed');
            throw new \RuntimeException("M365 refresh failed: " . ($resp['error'] ?? 'unknown'));
        }
        $body = $resp['body'] ?? [];
        $this->persistTokens($connectionId, $body, /* keepRefreshIfMissing */ $refresh);
    }

    public function revoke(int $connectionId): void
    {
        // Microsoft Graph doesn't expose a per-token revoke endpoint for
        // delegated tokens; admin must revoke via Azure portal. We mark the
        // connection revoked locally so polling stops immediately.
        $this->markStatus($connectionId, 'revoked', null);
    }

    /**
     * Poll a folder for new messages. $folderId is the row id in
     * tenant_mail_folders (NOT the Graph folder id) — we look up the
     * provider folder + delta cursor from the row.
     */
    public function poll(int $folderId, ?string $cursor): array
    {
        $folder = $this->loadFolder($folderId);
        if (!$folder) throw new \RuntimeException("folder {$folderId} not found");
        $conn   = $this->loadConnection((int) $folder['connection_id']);
        if (!$conn) throw new \RuntimeException("connection missing for folder {$folderId}");

        $token = $this->getValidAccessToken($conn);

        $deltatoken = $cursor ?? ($folder['last_message_cursor'] ?? null);
        $providerFolderId = $folder['folder_id_at_provider'] ?? '';
        if ($providerFolderId === '') {
            throw new \RuntimeException("folder_id_at_provider not set on folder {$folderId}");
        }

        if ($deltatoken) {
            $url = self::GRAPH_BASE . "/me/mailFolders/{$providerFolderId}/messages/delta?\$deltatoken="
                 . rawurlencode($deltatoken);
        } else {
            // First sync — only fetch lightweight metadata; full body fetch happens per-message later.
            $url = self::GRAPH_BASE . "/me/mailFolders/{$providerFolderId}/messages/delta"
                 . "?\$select=id,subject,from,receivedDateTime,hasAttachments,bodyPreview,internetMessageId";
        }

        $messages = [];
        $nextCursor = $deltatoken;
        $safetyHops = 0;
        while ($url && $safetyHops++ < 50) {
            $resp = $this->httpGet($url, $token);
            if (!($resp['ok'] ?? false)) {
                if (($resp['http'] ?? 0) === 401) {
                    // Token rejected — try one refresh + retry
                    $this->refresh_oauth((int) $conn['id']);
                    $conn  = $this->loadConnection((int) $conn['id']);
                    $token = $this->getValidAccessToken($conn);
                    $resp  = $this->httpGet($url, $token);
                    if (!($resp['ok'] ?? false)) {
                        throw new \RuntimeException('M365 poll auth failed: ' . ($resp['error'] ?? 'unknown'));
                    }
                } elseif (($resp['http'] ?? 0) === 429) {
                    // Throttled — return what we have; cron picks up later.
                    break;
                } else {
                    throw new \RuntimeException('M365 poll error: ' . ($resp['error'] ?? 'unknown'));
                }
            }
            $body = $resp['body'] ?? [];
            foreach ($body['value'] ?? [] as $m) {
                if (isset($m['@removed'])) continue; // delta-deleted message marker
                $from = $m['from']['emailAddress'] ?? [];
                $messages[] = [
                    'message_id'         => $m['id'] ?? null,
                    'internet_message_id'=> $m['internetMessageId'] ?? null,
                    'subject'            => $m['subject']          ?? '',
                    'from_address'       => $from['address']       ?? null,
                    'from_name'          => $from['name']          ?? null,
                    'received_at'        => $m['receivedDateTime'] ?? null,
                    'has_attachments'    => !empty($m['hasAttachments']),
                    'body_preview'       => $m['bodyPreview']      ?? '',
                ];
            }
            if (!empty($body['@odata.deltaLink'])) {
                $nextCursor = $this->extractDeltaToken((string) $body['@odata.deltaLink']);
                $url = null;
            } elseif (!empty($body['@odata.nextLink'])) {
                $url = (string) $body['@odata.nextLink'];
            } else {
                $url = null;
            }
        }

        // Persist new cursor on the folder row.
        $this->persistFolderCursor($folderId, $nextCursor);

        return ['messages' => $messages, 'next_cursor' => $nextCursor];
    }

    /**
     * Build the OAuth authorization URL with PKCE.
     * Caller is responsible for storing $verifier + $state in their session.
     */
    public function build_authorize_url(string $state, string $codeVerifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $params = [
            'client_id'             => $this->clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $this->redirectUri,
            'response_mode'         => 'query',
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ];
        return self::AUTHZ_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange auth code for tokens. Returns the parsed token response on
     * success or throws on failure. Does NOT persist; caller fetches the
     * user's profile and inserts the connection row.
     */
    public function exchange_code(string $code, string $codeVerifier): array
    {
        $resp = $this->httpPost(self::TOKEN_URL, [], http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]), 'application/x-www-form-urlencoded');
        if (!($resp['ok'] ?? false)) {
            throw new \RuntimeException('Token exchange failed: ' . ($resp['error'] ?? 'unknown'));
        }
        return $resp['body'] ?? [];
    }

    /** Fetch the signed-in user's profile (email, display name) using the access token. */
    public function fetch_me(string $accessToken): array
    {
        $resp = $this->httpGet(self::GRAPH_BASE . '/me?$select=id,displayName,mail,userPrincipalName', $accessToken);
        if (!($resp['ok'] ?? false)) {
            throw new \RuntimeException('Failed to fetch /me: ' . ($resp['error'] ?? 'unknown'));
        }
        return $resp['body'] ?? [];
    }

    /** List the user's mail folders so the tenant admin can pick one to watch. */
    public function list_mail_folders(int $connectionId, ?string $parentFolderId = null): array
    {
        $conn  = $this->loadConnection($connectionId);
        if (!$conn) throw new \RuntimeException("connection {$connectionId} not found");
        $token = $this->getValidAccessToken($conn);

        $url = $parentFolderId
            ? self::GRAPH_BASE . "/me/mailFolders/{$parentFolderId}/childFolders?\$top=100"
            : self::GRAPH_BASE . "/me/mailFolders?\$top=100";
        $resp = $this->httpGet($url, $token);
        if (!($resp['ok'] ?? false)) {
            throw new \RuntimeException('list_mail_folders failed: ' . ($resp['error'] ?? 'unknown'));
        }
        $out = [];
        foreach (($resp['body']['value'] ?? []) as $f) {
            $out[] = [
                'id'                  => $f['id']                  ?? null,
                'display_name'        => $f['displayName']         ?? '',
                'parent_folder_id'    => $f['parentFolderId']      ?? null,
                'child_folder_count'  => (int) ($f['childFolderCount'] ?? 0),
                'unread_item_count'   => (int) ($f['unreadItemCount']  ?? 0),
                'total_item_count'    => (int) ($f['totalItemCount']   ?? 0),
            ];
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Storage helpers (touch DB through getDB())
    // -----------------------------------------------------------------------
    public function persistTokens(int $connectionId, array $token, ?string $keepRefreshIfMissing = null): void
    {
        $access  = $token['access_token']  ?? null;
        $refresh = $token['refresh_token'] ?? $keepRefreshIfMissing;
        $expSec  = (int) ($token['expires_in'] ?? 3600);
        $expAt   = gmdate('Y-m-d H:i:s', time() + $expSec);

        $pdo = getDB();
        $stmt = $pdo->prepare(
            'UPDATE tenant_mail_connections
             SET oauth_access_token_ct = :a, oauth_refresh_token_ct = :r,
                 oauth_expires_at = :e, oauth_scope = :s, status = "active", error_message = NULL
             WHERE id = :id'
        );
        $stmt->bindValue('a',  $access  ? encryptField($access)  : null, $access  ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue('r',  $refresh ? encryptField($refresh) : null, $refresh ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue('e',  $expAt);
        $stmt->bindValue('s',  $token['scope'] ?? self::SCOPES);
        $stmt->bindValue('id', $connectionId, \PDO::PARAM_INT);
        $stmt->execute();
    }

    private function getValidAccessToken(array $conn): string
    {
        $exp = isset($conn['oauth_expires_at']) ? strtotime((string) $conn['oauth_expires_at']) : 0;
        if ($exp - time() < 300) {
            $this->refresh_oauth((int) $conn['id']);
            $conn = $this->loadConnection((int) $conn['id']);
        }
        $access = decryptField($conn['oauth_access_token_ct'] ?? null);
        if (!$access) throw new \RuntimeException('access_token decrypt failed');
        return $access;
    }

    private function loadConnection(int $id): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM tenant_mail_connections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function loadFolder(int $id): ?array
    {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT * FROM tenant_mail_folders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function persistFolderCursor(int $folderId, ?string $cursor): void
    {
        if (!$cursor) return;
        $pdo = getDB();
        $pdo->prepare('UPDATE tenant_mail_folders SET last_message_cursor = :c, last_polled_at = NOW() WHERE id = :id')
            ->execute(['c' => $cursor, 'id' => $folderId]);
    }

    private function markStatus(int $id, string $status, ?string $errorMessage): void
    {
        $pdo = getDB();
        $pdo->prepare('UPDATE tenant_mail_connections SET status = :s, error_message = :e WHERE id = :id')
            ->execute(['s' => $status, 'e' => $errorMessage, 'id' => $id]);
    }

    public function extractDeltaToken(string $deltaLink): string
    {
        $q = parse_url($deltaLink, PHP_URL_QUERY) ?: '';
        parse_str($q, $params);
        return (string) ($params['$deltatoken'] ?? '');
    }

    // -----------------------------------------------------------------------
    // HTTP — single funnel so tests can inject a transport closure
    // -----------------------------------------------------------------------
    private function httpGet(string $url, string $accessToken): array
    {
        return $this->doHttp(['method' => 'GET', 'url' => $url, 'token' => $accessToken]);
    }

    private function httpPost(string $url, array $headers, string $body, string $contentType): array
    {
        return $this->doHttp([
            'method'       => 'POST',
            'url'          => $url,
            'headers'      => $headers,
            'body'         => $body,
            'content_type' => $contentType,
        ]);
    }

    private function doHttp(array $req): array
    {
        if ($this->transport) return ($this->transport)($req);

        $ch = curl_init($req['url']);
        $hdr = $req['headers'] ?? [];
        if (!empty($req['token']))         $hdr[] = 'Authorization: Bearer ' . $req['token'];
        if (!empty($req['content_type']))  $hdr[] = 'Content-Type: ' . $req['content_type'];
        $hdr[] = 'Accept: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $hdr,
        ]);
        if (($req['method'] ?? 'GET') === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body'] ?? '');
        }
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'http' => 0, 'error' => 'cURL: ' . $err];
        $decoded = json_decode((string) $body, true) ?: [];
        if ($http >= 200 && $http < 300) return ['ok' => true, 'http' => $http, 'body' => $decoded];
        $msg = $decoded['error']['message']
            ?? $decoded['error_description']
            ?? ('HTTP ' . $http);
        return ['ok' => false, 'http' => $http, 'error' => (string) $msg, 'body' => $decoded];
    }
}
