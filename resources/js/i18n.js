/**
 * Translation for the player's JavaScript.
 *
 * `php artisan lang:audit` scans PHP and Blade for `__()`, so it reported all five languages at
 * 100% while every string built in JavaScript stayed English. A French class answering a quiz
 * still read "Nice!" and "No worries, you will hear this later in the story!".
 *
 * Blade publishes the strings the player needs into `window.__lpLang` (see the js-lang component),
 * drawn from the same `lang/*.json` files as the rest of the interface — one source of truth, one
 * audit. This is only the lookup.
 *
 * Keys are the English source text, exactly like Laravel's JSON translations, so a string reads as
 * itself at the call site and an untranslated language degrades to English instead of to a key.
 *
 *   t('Nice!')
 *   t('answers unlock in :count', { count: 3 })
 */

/** @param {string} source  @param {Record<string, string|number>} [replace] */
export function t (source, replace = {}) {
  const dict = window.__lpLang
  // A dictionary that failed to render is not worth throwing over in the middle of a quiz.
  const line = (dict && typeof dict === 'object' && typeof dict[source] === 'string')
    ? dict[source]
    : source

  return Object.keys(replace).reduce(
    (out, key) => out.replaceAll(`:${key}`, String(replace[key])),
    line,
  )
}
