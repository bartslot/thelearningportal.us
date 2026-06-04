import { describe, it, expect } from 'vitest';
import { yearsAgo, generations, formatReadout } from './era.js';

describe('era math (mirrors App\\Services\\EraService)', () => {
  it('years ago for BCE adds to current year', () => {
    expect(yearsAgo(-1500, 2026)).toBe(3526);
  });
  it('years ago for CE', () => {
    expect(yearsAgo(1000, 2026)).toBe(1026);
  });
  it('generations default 25y', () => {
    expect(generations(-1500, 2026)).toBe(141);
  });
  it('formats a kid-facing readout', () => {
    expect(formatReadout(-1500, 2026)).toBe('≈ 3,526 years ago · ~141 generations');
  });
});
