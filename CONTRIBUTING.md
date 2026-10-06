# Contributing

Thanks for your interest. Blueline is built for one league's site, so changes that fit that site are the easiest
to accept. Bug fixes, accessibility and performance improvements, and test coverage are always welcome. For a
larger feature, open an issue first so we can agree on the shape before you spend time on it.

By contributing you agree that your work is licensed under GPL-2.0-or-later, like the rest of the project.

## Set up

See the *Develop* section of the [README](README.md). You need PHP 8.3, Composer, and Node 20.

## Before you open a pull request

1. Branch from `main`. Never push to `main` directly; every change goes through a pull request.
2. Keep the change focused: one fix or feature per pull request.
3. Run the gates for what you touched, and make sure they pass:
   - Theme: `npm run check` in `themes/blueline` (CSS and JS lint, JS tests, colour-contrast check,
     PHPUnit, PHP lint)
   - Plugin: `composer test && composer lint` in `plugins/blueline-core`
4. If you changed CSS or JS, run `npm run build` and commit the generated `themes/blueline/assets/dist` files.
5. Add or update tests for behaviour you change. Match the surrounding code's style, naming and comment density.
6. Add a line under `## [Unreleased]` in `themes/blueline/CHANGELOG.md` for anything a user would notice.
7. Write commit messages in the conventional style the history uses (`feat:`, `fix:`, `refactor:`, `chore:`,
   with a scope such as `theme` or `blueline-core` where it helps).

CI runs the same gates plus an end-to-end browser suite. Please make sure the `check` job is green before asking for review.

## Standards

- PHP follows the WordPress Coding Standards (`composer lint`), including docblocks, Yoda conditions and escaped output.
- Accessibility is a requirement, not a polish step: colour contrast is enforced (`npm run tokens:check`), and
  interactive elements need keyboard access and accessible names.
- Colours and spacing come from the design tokens; do not hard-code new values. See [`docs/DESIGN.md`](docs/DESIGN.md).
- Never commit secrets, real host names or IP addresses, or member data. Test data should be obviously fake.
- Two PRs that both run `npm run build` will conflict on `assets/dist`. If that happens, merge `main` and
  rebuild rather than resolving the generated files by hand.

## Reporting bugs and security issues

Use the issue templates for bugs and feature requests. For anything security-related, do **not** open an
issue; follow [`SECURITY.md`](SECURITY.md).
