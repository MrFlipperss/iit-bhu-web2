# Third-party software and services

| Name | Version | Licence | Used for |
|---|---|---|---|
| Google OR-Tools (CP-SAT) | 9.15.6755 | Apache-2.0 | Improving the timetable and exam plan; minimum-movement repair. Optional: the engine falls back to its own search without it |
| Crunch Week starter pack (`validator.py`, dataset) | as supplied | Hackathon organisers | Data and official validation; the validator is imported unmodified |
| PHP | 8.3 | PHP License 3.01 | Web backend |
| Apache HTTP Server (Docker image `php:8.3-apache`) | 2.4 | Apache-2.0 | Web server in the container |
| MySQL | 8.0 | GPL-2.0 (server) | Database |
| Python | 3.9+ | PSF | Engine runtime |
| IBM Plex Sans and IBM Plex Mono (Google Fonts) | current | SIL OFL 1.1 | Interface typefaces |
| Lucide icon paths (inlined in `public/assets/js/app.js`) | 0.x | ISC | Interface icons |
| Firebase Hosting, Google Cloud Run, Cloud SQL | services | Google terms | Hosting |
| LLM / embedding provider (set via `LLM_PROVIDER`) | service | provider terms | Optional AI features (see AI_USAGE.md) |

No frontend framework or PHP package manager is used.
