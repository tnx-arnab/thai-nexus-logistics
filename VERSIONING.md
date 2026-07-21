# Versioning policy

**Current release line: `1.5.x` (latest: `1.5.11`)**

## Rules

- Use **patch** bumps only: `1.5.11` → `1.5.12` → `1.5.13`, etc.
- Update together when releasing:
  - `thai-nexus-logistics.php` — `Version` header and `TNXL_VERSION`
  - `readme.txt` — `Stable tag` and changelog section
  - SVN tag folder: `tags/1.5.x`

## Do not bump without explicit approval

- **Do not** move to `1.6`, `1.7`, `2.0`, or any new minor/major line unless the project owner says so.
- Large features still ship as `1.5.x` patches until a minor bump is requested.

## SVN deploy

```bash
./deploy-svn.sh "Your message" 1.5.11
```

The deploy script blocks tags outside `1.5.x` unless you set:

```bash
ALLOW_MAJOR_VERSION=1 ./deploy-svn.sh "message" 1.6.0
```
