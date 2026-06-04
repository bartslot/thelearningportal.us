// Mirror of App\Services\EraService — keep the two in sync (both are tested).
export function yearsAgo(year, currentYear = new Date().getFullYear()) {
  return currentYear - year;
}

export function generations(year, currentYear = new Date().getFullYear(), perGeneration = 25) {
  return Math.trunc(yearsAgo(year, currentYear) / perGeneration);
}

export function formatReadout(year, currentYear = new Date().getFullYear()) {
  const ya = yearsAgo(year, currentYear).toLocaleString('en-US');
  const gens = generations(year, currentYear);
  return `≈ ${ya} years ago · ~${gens} generations`;
}
