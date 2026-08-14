---
name: Bug report
about: Something doesn't work as documented when run locally
title: ''
labels: bug
assignees: ''
---

> Reminder: Luna is a local study artifact (PHP 8.3), run on
> `localhost`. Production/deployment issues are out of scope — see SECURITY.md.

**What happened**
A clear description of the bug.

**Steps to reproduce**
1. `docker-compose up --build -d`
2. …

**Expected vs actual**

**Environment**
- Luna version (`$lunaVersion` in `luna/luna.php`):
- Read path: default (Oxigraph) / Ontop / `?sparql=0` (SQL):
- Docker / OS:

**Logs**
Relevant output from `docker logs luna-app-1` (scrub anything sensitive).
