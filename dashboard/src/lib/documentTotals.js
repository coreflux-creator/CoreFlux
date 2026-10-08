export function roundMoney(value) {
  const amount = Number(value) || 0;
  return Math.sign(amount) * Math.round((Math.abs(amount) + Number.EPSILON) * 100) / 100;
}

export function calculateDocumentTotals(lines, taxPct, { discountsUntaxed = false } = {}) {
  const rate = (Number(taxPct) || 0) / 100;
  let subtotal = 0;
  let taxTotal = 0;
  for (const line of lines) {
    const lineSubtotal = roundMoney((Number(line.quantity) || 0) * (Number(line.unit_price) || 0));
    const taxable = line.taxable !== false && !(discountsUntaxed && line.item_type === 'discount');
    subtotal += lineSubtotal;
    if (taxable) taxTotal += roundMoney(lineSubtotal * rate);
  }
  subtotal = roundMoney(subtotal);
  taxTotal = roundMoney(taxTotal);
  return { subtotal, taxTotal, total: roundMoney(subtotal + taxTotal) };
}
