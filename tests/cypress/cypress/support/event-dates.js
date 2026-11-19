/**
 * Date helpers for event specs, so no spec types a fixed date that expires.
 *
 * Every date is an offset in days from RUN_DATE, which cypress.config.js sets
 * once per run (override it with CYPRESS_RUN_DATE).
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * A date a number of days after the run date.
 *
 * @param {number} offsetDays
 *   Days after RUN_DATE. Event specs start at least 60 days out so nothing
 *   falls inside a reminder or registration window.
 *
 * @returns {{iso: string, short: string, day: string, month: string}}
 *   iso is YYYY-MM-DD for the event form, short is MM/DD/YY as the event page
 *   shows it, and day and month are the unpadded day and short month name the
 *   events listing shows.
 */
export function eventDate(offsetDays) {
  if (!Number.isInteger(offsetDays)) {
    throw new Error(`eventDate() needs an integer offset, got ${offsetDays}`);
  }
  // Work in UTC so a daylight-saving change can't shift the date by a day.
  const base = Date.parse(`${Cypress.env('RUN_DATE')}T00:00:00Z`);
  const iso = new Date(base + offsetDays * DAY_MS).toISOString().slice(0, 10);
  const [year, month, day] = iso.split('-');
  return {
    iso,
    short: `${month}/${day}/${year.slice(-2)}`,
    day: String(Number(day)),
    month: MONTHS[Number(month) - 1],
  };
}

/**
 * The anonymous test event's date, created by events-add.cy.js and checked by
 * events-page.cy.js. Thirty days out keeps it in the future so the event
 * still offers registration.
 *
 * @returns {{iso: string, short: string, day: string, month: string}}
 */
export function anonEventDate() {
  return eventDate(30);
}
