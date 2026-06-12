<?php
/**
 * CoreFlux Module Registry
 *
 * Discovers, validates, and exposes module manifests at runtime.
 *
 * Design intent:
 *   - Single source of truth for "what modules exist on this install."
 *   - Auto-discovers `manifest.php` files in `/app/modules/<id>/`.
 *   - Tolerates partial manifests; missing fields get sensible defaults
 *     so existing modules continue to work while the manifest schema is
 *     being completed (per Tier 1 plan).
 *   - Coexists with the legacy `core/modules.php` hardcoded list. Both run
 *     side-by-side until consumers (session.php, API router, RBAC) migrate
 *     over. Nothing is deleted.
 *   - Singleton: scans once per request, caches.
 *
 * Hard rules respected (see /app/memory/HARD_RULES.md):
 *   - R1: pre-existing files untouched.
 *   - R2: registry is core scaffolding, not a module.
 *
 * Usage:
 *   $registry = ModuleRegistry::getInstance();
 *   $allModules = $registry->getAllModules();
 *   $people     = $registry->getModule('people');
 *   $errors     = $registry->getValidationErrors(); // diagnostics
 */

declare(strict_types=1);

class ModuleRegistry {
    private static ?ModuleRegistry $instance = null;

    /** @var array<string, array> module_id => manifest (with defaults applied) */
    private array $modules = [];

    /** @var array<string, list<string>> module_id => list of validation messages */
    private array $validationErrors = [];

    /** @var string Absolute path to /app/modules */
    private string $modulesDir;

    private function __construct(?string $modulesDir = null) {
        $this->modulesDir = $modulesDir ?? dirname(__DIR__) . '/modules';
        $this->discover();
    }

    public static function getInstance(): ModuleRegistry {
        if (self::$instance === null) {
            self::$instance = new ModuleRegistry();
        }
        return self::$instance;
    }

    /**
     * Re-scan from disk. Useful after a deploy or in tests.
     * (Tests can also instantiate a fresh registry via reset().)
     */
    public static function reset(?string $modulesDir = null): ModuleRegistry {
        self::$instance = new ModuleRegistry($modulesDir);
        return self::$instance;
    }

    // ----------------------------------------------------------------------
    // Discovery
    // ----------------------------------------------------------------------

    private function discover(): void {
        $this->modules = [];
        $this->validationErrors = [];

        if (!is_dir($this->modulesDir)) {
            error_log("ModuleRegistry: modules dir not found at {$this->modulesDir}");
            return;
        }

        $entries = scandir($this->modulesDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;

            $modulePath = $this->modulesDir . '/' . $entry;
            if (!is_dir($modulePath)) continue;

            // Skip developer scaffolds and any folder whose id starts with
            // an underscore (e.g. _template).
            if (str_starts_with($entry, '_')) continue;

            $manifestPath = $modulePath . '/manifest.php';
            if (!file_exists($manifestPath)) {
                // Folder exists but no manifest — skip silently. The folder
                // may be a placeholder (private_equity, tax stubs etc.)
                // until that module is built.
                continue;
            }

            $manifest = $this->loadManifest($manifestPath, $entry);
            if ($manifest === null) continue;

            $id = $manifest['id'];
            if (isset($this->modules[$id])) {
                $this->validationErrors[$id][] = "duplicate module id; later one ignored";
                continue;
            }

            $this->modules[$id] = $manifest;
        }
    }

    /**
     * Load + validate one manifest. Returns null if it's unusable
     * (missing both `id` AND we can't infer one from the folder name).
     * Otherwise returns the manifest with defaults filled in.
     */
    private function loadManifest(string $path, string $folderName): ?array {
        try {
            /** @var mixed $raw */
            $raw = require $path;
        } catch (\Throwable $e) {
            error_log("ModuleRegistry: error loading $path: " . $e->getMessage());
            $this->validationErrors[$folderName][] = "manifest threw: " . $e->getMessage();
            return null;
        }

        if (!is_array($raw)) {
            error_log("ModuleRegistry: $path did not return an array");
            $this->validationErrors[$folderName][] = "manifest did not return an array";
            return null;
        }

        // `id` is required. Fall back to folder name if missing (with warning).
        $id = $raw['id'] ?? $raw['module_id'] ?? null;
        if (!is_string($id) || $id === '') {
            $id = $folderName;
            $this->validationErrors[$folderName][] = "manifest missing 'id'; using folder name '$folderName'";
        }
        if ($id !== $folderName) {
            $this->validationErrors[$id][] = "manifest id '$id' differs from folder name '$folderName'";
        }

        // `name` is required for human-readable display. Fall back to id.
        $name = $raw['name'] ?? null;
        if (!is_string($name) || $name === '') {
            $name = ucfirst(str_replace('_', ' ', $id));
            $this->validationErrors[$id][] = "manifest missing 'name'; defaulted to '$name'";
        }

        // Apply defaults for optional fields. Future fields (nav_sections,
        // audit_events, etc.) live here — adding them is a one-line change.
        $manifest = array_merge([
            'id'                    => $id,
            'name'                  => $name,
            'icon'                  => "/assets/icons/icon-{$id}.png",
            'description'           => '',
            'version'               => '0.0.1',
            'actions'               => [],
            'views'                 => [],
            'nav_sections'          => [],
            'permissions'           => [],
            'audit_events'          => [],
            'workflows'             => [],
            'exports'               => [],
            'export_datasets'       => [],
            'report_datasets'       => [],
            'custom_field_layouts'   => [],
            'default_roles'         => [],
            'depends_on'            => [],
            'custom_field_entities' => [],
            'people_graph'          => [],
        ], $raw);

        // Type-check the most-used fields. Non-fatal; just warn and coerce.
        foreach (['actions', 'views', 'nav_sections', 'permissions',
                  'audit_events', 'default_roles', 'depends_on',
                  'custom_field_entities', 'custom_field_layouts',
                  'export_datasets', 'report_datasets'] as $listField) {
            if (!is_array($manifest[$listField])) {
                $this->validationErrors[$id][] = "field '$listField' must be an array; coerced to []";
                $manifest[$listField] = [];
            }
        }
        if (!is_array($manifest['people_graph'])) {
            $this->validationErrors[$id][] = "field 'people_graph' must be an array; coerced to []";
            $manifest['people_graph'] = [];
        }

        return $manifest;
    }

    // ----------------------------------------------------------------------
    // Public accessors
    // ----------------------------------------------------------------------

    /** @return array<string, array> all registered modules keyed by id */
    public function getAllModules(): array {
        return $this->modules;
    }

    public function getModule(string $moduleId): ?array {
        return $this->modules[$moduleId] ?? null;
    }

    public function hasModule(string $moduleId): bool {
        return isset($this->modules[$moduleId]);
    }

    /** @return array<string> all module ids in registration order */
    public function getModuleIds(): array {
        return array_keys($this->modules);
    }

    /**
     * Return modules whose `default_roles` include $role.
     * This is a temporary helper to mirror the old getUserModules($role)
     * behaviour while RBAC is being built. Once RBAC.php exists, callers
     * should switch to permission-based filtering.
     */
    public function getModulesForRole(string $role): array {
        $matched = [];
        foreach ($this->modules as $id => $m) {
            $roles = $m['default_roles'] ?? [];
            if ($role === 'master_admin' || in_array($role, $roles, true)) {
                $matched[$id] = $m;
            }
        }
        return $matched;
    }

    /**
     * Flat list of every permission KEY declared by every module.
     * Used by RBAC seeding + admin UIs.
     *
     * Accepts both manifest shapes:
     *   - flat list:  ['people.view', 'people.manage']
     *   - assoc map:  ['people.view' => 'View employee directory', ...]
     *
     * @return array<string>
     */
    public function getAllPermissions(): array {
        $perms = [];
        foreach ($this->modules as $m) {
            foreach (($m['permissions'] ?? []) as $key => $val) {
                // Assoc map shape: key is the permission, val is the description.
                if (is_string($key) && !is_int($key)) {
                    $perms[] = $key;
                    continue;
                }
                // Flat list shape: val is the permission string.
                if (is_string($val)) {
                    $perms[] = $val;
                } elseif (is_array($val) && isset($val['key'])) {
                    $perms[] = $val['key'];
                }
            }
            // Also harvest permissions referenced from action items (legacy shape).
            foreach (($m['actions'] ?? []) as $a) {
                if (!empty($a['permission']) && is_string($a['permission'])) {
                    $perms[] = $a['permission'];
                }
            }
        }
        return array_values(array_unique($perms));
    }

    /**
     * Return every custom-field entity declared by module manifests.
     *
     * Each row is normalized to a common platform shape. Manifests may declare
     * either strings:
     *   'people'
     * or maps:
     *   ['entity_type' => 'people', 'label' => 'People', ...]
     *
     * @return array<string, array> entity_type => metadata
     */
    public function getCustomFieldEntities(): array {
        $out = [];
        $defaultSurfaces = ['forms', 'detail', 'lists', 'exports', 'reports'];
        $allowedSurfaces = array_flip($defaultSurfaces);
        foreach ($this->modules as $moduleId => $m) {
            $layouts = is_array($m['custom_field_layouts'] ?? null) ? $m['custom_field_layouts'] : [];
            foreach (($m['custom_field_entities'] ?? []) as $entry) {
                $raw = is_array($entry) ? $entry : ['entity_type' => (string) $entry];
                $entityType = (string) ($raw['entity_type'] ?? $raw['id'] ?? '');
                if ($entityType === '') continue;
                if (isset($out[$entityType])) {
                    $owner = (string) ($out[$entityType]['module_id'] ?? 'unknown');
                    $this->validationErrors[$moduleId][] = "custom field entity '$entityType' already owned by '$owner'; duplicate ignored";
                    continue;
                }

                $surfaceRaw = $raw['surfaces'] ?? $defaultSurfaces;
                if (!is_array($surfaceRaw)) {
                    $this->validationErrors[$moduleId][] = "custom field entity '$entityType' surfaces must be an array; defaulted";
                    $surfaceRaw = $defaultSurfaces;
                }
                $surfaces = [];
                foreach ($surfaceRaw as $surface) {
                    $surface = strtolower(trim((string) $surface));
                    if ($surface === '' || !isset($allowedSurfaces[$surface])) continue;
                    $surfaces[] = $surface;
                }
                $surfaces = array_values(array_unique($surfaces));
                if ($surfaces === []) {
                    $this->validationErrors[$moduleId][] = "custom field entity '$entityType' surfaces were empty/invalid; defaulted";
                    $surfaces = $defaultSurfaces;
                }

                $layoutDecl = $raw['layouts'] ?? ($layouts[$entityType] ?? []);
                if (!is_array($layoutDecl)) {
                    $this->validationErrors[$moduleId][] = "custom field entity '$entityType' layouts must be an array; coerced to []";
                    $layoutDecl = [];
                }

                $out[$entityType] = array_merge([
                    'entity_type'       => $entityType,
                    'module_id'         => $moduleId,
                    'label'             => ucfirst(str_replace('_', ' ', $entityType)),
                    'view_permission'   => $moduleId . '.view',
                    'manage_permission' => $moduleId . '.custom_fields.manage',
                    'pii_permission'    => null,
                    'definition_table'  => null,
                    'value_table'       => null,
                    'record_id_key'     => 'record_id',
                    'surfaces'          => $defaultSurfaces,
                    'layouts'           => $layoutDecl,
                ], $raw, [
                    'entity_type' => $entityType,
                    'module_id'   => $moduleId,
                    'surfaces'    => $surfaces,
                    'layouts'     => $layoutDecl,
                ]);
            }
        }
        return $out;
    }

    public function getCustomFieldEntity(string $entityType): ?array {
        $all = $this->getCustomFieldEntities();
        return $all[$entityType] ?? null;
    }

    /**
     * Return export datasets declared by module manifests.
     *
     * The execution registry still lives in core/export_datasets.php; this
     * manifest view makes dataset ownership, permissions, and audit events
     * discoverable without loading export execution code.
     *
     * @return array<string, array> dataset key => metadata
     */
    public function getExportDatasetDeclarations(): array {
        return $this->getDatasetDeclarations('export_datasets');
    }

    /**
     * Return report-builder datasets declared by module manifests.
     *
     * @return array<string, array> dataset key => metadata
     */
    public function getReportDatasetDeclarations(): array {
        return $this->getDatasetDeclarations('report_datasets');
    }

    /** @internal */
    private function getDatasetDeclarations(string $field): array {
        $out = [];
        foreach ($this->modules as $moduleId => $manifest) {
            foreach (($manifest[$field] ?? []) as $key => $entry) {
                $raw = is_array($entry) ? $entry : ['dataset' => (string) $entry];
                $dataset = (string) ($raw['dataset'] ?? $raw['key'] ?? (is_string($key) ? $key : ''));
                if ($dataset === '') continue;
                if (isset($out[$dataset])) {
                    $owner = (string) ($out[$dataset]['module_id'] ?? 'unknown');
                    $this->validationErrors[$moduleId][] = "{$field} dataset '$dataset' already declared by '$owner'; duplicate ignored";
                    continue;
                }
                $out[$dataset] = array_merge([
                    'dataset' => $dataset,
                    'module_id' => $moduleId,
                    'label' => ucwords(str_replace('_', ' ', $dataset)),
                    'permission' => null,
                    'formats' => [],
                    'audit_event' => null,
                    'custom_field_entities' => [],
                    'sensitive_fields' => [],
                    'source' => $field === 'report_datasets' ? 'export_dataset' : 'registry',
                ], $raw, [
                    'dataset' => $dataset,
                    'module_id' => $moduleId,
                    'formats' => array_values((array) ($raw['formats'] ?? [])),
                    'custom_field_entities' => array_values((array) ($raw['custom_field_entities'] ?? [])),
                    'sensitive_fields' => array_values((array) ($raw['sensitive_fields'] ?? [])),
                ]);
            }
        }
        return $out;
    }

    /**
     * Return People Graph consumption contracts declared by module manifests.
     *
     * The contract is intentionally manifest-owned so domain modules can state
     * which object types consume shared authority/responsibility routing.
     *
     * @return array<string, array> module_id => people_graph contract
     */
    public function getPeopleGraphContracts(): array {
        $out = [];
        foreach ($this->modules as $moduleId => $m) {
            $contract = $m['people_graph'] ?? [];
            if (!is_array($contract) || $contract === []) continue;
            $out[$moduleId] = array_merge([
                'module_id'    => $moduleId,
                'consumes'     => false,
                'mode'         => 'source_module_consumer',
                'object_types' => [],
            ], $contract, [
                'module_id' => $moduleId,
            ]);
        }
        return $out;
    }

    public function getPeopleGraphContract(string $moduleId): ?array {
        $contracts = $this->getPeopleGraphContracts();
        return $contracts[$moduleId] ?? null;
    }

    /**
     * Map of permission_key => human_readable_description across all modules.
     * Useful for an admin UI that wants to render checkboxes with labels.
     *
     * @return array<string, string>
     */
    public function getAllPermissionsWithDescriptions(): array {
        $out = [];
        foreach ($this->modules as $m) {
            foreach (($m['permissions'] ?? []) as $key => $val) {
                if (is_string($key) && !is_int($key)) {
                    $out[$key] = is_string($val) ? $val : $key;
                } elseif (is_string($val)) {
                    if (!isset($out[$val])) $out[$val] = $val;
                }
            }
        }
        return $out;
    }

    // ----------------------------------------------------------------------
    // Diagnostics
    // ----------------------------------------------------------------------

    /** @return array<string, list<string>> validation messages per module id */
    public function getValidationErrors(): array {
        return $this->validationErrors;
    }

    public function summary(): array {
        $rows = [];
        foreach ($this->modules as $id => $m) {
            $rows[] = [
                'id'           => $id,
                'name'         => $m['name'],
                'permissions'  => count($m['permissions'] ?? []),
                'actions'      => count($m['actions'] ?? []),
                'nav_sections' => count($m['nav_sections'] ?? []),
                'audit_events' => count($m['audit_events'] ?? []),
                'warnings'     => $this->validationErrors[$id] ?? [],
            ];
        }
        return $rows;
    }
}
