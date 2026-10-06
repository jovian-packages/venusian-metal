# Security Policy

## Supported versions

Venusian is pre-1.0. No 0.x release receives security fixes or advisories; fixes land in the
next release line. Security support starts with 1.0.

| Version | Security fixes |
|---------|----------------|
| < 1.0   | No             |

## Reporting a vulnerability

Please don't open a public issue for a security problem.

Report it privately through GitHub: the **Report a vulnerability** button on this repository's
**Security** tab. If that isn't available, email **info@projectsaturnstudios.com**.

Include what you found, the affected version, and steps to reproduce. Reports are read and
weighed for the release line in development; before 1.0 there is no response-time commitment.

## Security model

- The shaders are the package's own file, compiled at start, never user input.
- A `LentSurface` handle is an address trusted to be a `CAMetalLayer` (ext-metal's `fromPointer` rule). It comes only from a toolkit package's canvas.
- Image sources are read through `toRgba8()`, sized by the framebuffer, never by caller-supplied lengths.
- Readback regions are bounded by `GLFramebuffer::flushRegion()`'s check.
