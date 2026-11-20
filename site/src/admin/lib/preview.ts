// Read-only preview of the back office without signing in (/admin/?apercu).
// Uses the public (anonymous) database access only: the catalog and public settings are visible,
// orders, customers and sales stay hidden by the database security rules, and nothing can be saved.
export const PREVIEW = typeof location !== 'undefined' && new URLSearchParams(location.search).has('apercu');
