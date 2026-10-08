const MODULE_ROOTS = [
  '/modules/accounting', '/modules/billing', '/modules/ap', '/modules/treasury',
];
const SHARED_ROOTS = ['/inbox', '/profile', '/admin', '/select-tenant'];
const SETTINGS_PATHS = new Set(['/settings', '/settings/mail']);

export function coreAccountingPathAllowed(pathname) {
  if (SETTINGS_PATHS.has(pathname)) return true;
  return [...MODULE_ROOTS, ...SHARED_ROOTS].some((root) =>
    pathname === root || pathname.startsWith(`${root}/`));
}
