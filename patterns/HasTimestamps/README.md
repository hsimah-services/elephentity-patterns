# HasTimestamps

Adds `createdAt` and `updatedAt` datetime fields. The runtime supplies both on
creation and refreshes `updatedAt` on writes. Generated input APIs do not expose
setters for managed fields.

Apply with `use: [HasTimestamps]`. No custom implementation, runtime package beyond
Elephentity, or other pattern is required. The generated pattern interface provides
shared getters across consuming entities.

This records framework writes, not direct SQL changes. It does not provide an audit
log or track the current user. Existing rows need an application migration.
