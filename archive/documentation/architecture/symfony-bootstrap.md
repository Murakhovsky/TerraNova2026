# Parallel Symfony LTS bootstrap

COS now has a deliberately isolated Symfony migration target alongside the existing Phalcon runtime.

## Runtime contract

- Symfony: 7.4 LTS on PHP 8.3 FPM
- HTTP: dedicated Nginx container on `127.0.0.1:8081`
- Database: dedicated MySQL 8.4 volume
- Cache/queue foundation: dedicated Redis 7.4 volume
- Existing COS/Phalcon runtime on `127.0.0.1:8080` is not modified
- Runtime secrets live outside the repository at `~/.config/cos-symfony/runtime.env`

## Deployment

`deploy/symfony-dev.sh` builds and starts the isolated stack and verifies `/health` before reporting success.

The GitHub Actions workflow `.github/workflows/symfony-bootstrap.yml` validates the stack in CI and deploys the same files to the existing AWS dev host through the already configured deployment secrets.

## Migration rule

Traffic must not be switched from the existing COS runtime until migrated Symfony modules have explicit regression coverage and their production routes have been verified against the old implementation.

The first migration phase should introduce COS kernel contracts and adapters into `symfony/src/` without importing Phalcon framework dependencies.
