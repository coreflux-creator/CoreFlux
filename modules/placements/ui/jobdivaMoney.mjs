export function displayJobDivaMoney(value, currency, unit) {
  if (value === null || value === undefined || value === '') return '-';
  const number = Number(value);
  if (!Number.isFinite(number)) return String(value);
  const label = currency || 'Currency unverified';
  return `${label} ${number.toFixed(2)}${unit ? ` / ${unit}` : ''}`;
}
