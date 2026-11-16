/**
 * Date helpers for event specs that must agree on a generated date.
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * The anonymous test event's date, set once per run in cypress.config.js.
 *
 * @returns {{iso: string, short: string, day: string, month: string}}
 *   iso is YYYY-MM-DD for the event form, short is MM/DD/YY as the event page
 *   shows it, and day and month are the unpadded day and short month name the
 *   events listing shows.
 */
export function anonEventDate() {
  const iso = Cypress.env('ANON_EVENT_DATE');
  const [year, month, day] = iso.split('-');
  return {
    iso,
    short: `${month}/${day}/${year.slice(-2)}`,
    day: String(Number(day)),
    month: MONTHS[Number(month) - 1],
  };
}
