# Regular-season tables counting playoff games — change proposal

> ## ✅ APPLIED 2026-08-13 — all 71 tables. Fully revertible.
>
> Verified afterwards: **0 tables still count a playoff game**, and the 71 now match **4,330**
> regular-season events — exactly the "After" total this document predicted. 1,009 playoff games
> were removed from regular-season standings.
>
> ### How to revert
>
> Every changed table carries meta **`_arl_standings_fix_2026_08_12`** holding its previous
> `sp_date`, `sp_date_from`, `sp_date_to` and season term ids, so reverting needs no external file:
>
> ```bash
> scp scripts/revert-standings-fix-2026-08-12.php production-host:/tmp/arl-revert.php
> ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
>   eval-file /tmp/arl-revert.php --user=9 --skip-themes; rm -f /tmp/arl-revert.php'
> ```
>
> Reverts all 71 by default. Set `$ONLY` for a subset (S2025 = `112924, 112926, 112928, 112930,
> 112932`), `$EXCEPT` to keep some corrected, or `$DRY_RUN = true` to preview — the dry run has
> been tested and lists all 71 with their prior values without writing anything.
>
> A second, independent copy of the before-state is in `2026-08-12-standings-71-backup.json`
> next to this file.
>
> ### The one visible placing change
>
> **S2025 Division 5** — the only division in the whole set whose order moves:
>
> | | Before | After |
> |---|---|---|
> | 1 | Royals 29 | Royals 24 |
> | 2 | **Whalers 14** | **TrebleMakers 12** |
> | 3 | Train Wreck 13 | Train Wreck 11 |
> | 4 | **TrebleMakers 12** | **Whalers 9** |
>
> Whalers went 2-0-1 in the playoffs and TrebleMakers 0-3, which is what had lifted Whalers above
> them in the *regular-season* table. Every other division keeps its order; only GP and points fall.
>
> ### Why this is a correction, not a rewrite
>
> These tables were right during their seasons — they showed regular-only figures because no
> playoff results existed yet. They drifted the moment playoff scores were entered, because
> SportsPress forces `include_children = true` and each playoff season term is a child of its
> regular season term. The archives had stopped showing what they showed when the standings
> mattered for seeding.

Generated 2026-08-12 from live data. **W2015-16 excluded** (tables 63, 230–234) — needs care.

The proposed window is first..last **regular-season** event actually matching that table's
season + league, computed while ignoring the current window so a bad range cannot propagate.
Verified across all 71: no proposal leaves a playoff game inside the window, and no current
window is already dropping legitimate regular games.

`Now` = events the table matches today. `Drop` = playoff games among them. `After` = what
remains. `Exp` = playoff games expected at **4 per team** for the teams seen in that table's
regular season; where `Drop` ≠ `Exp` the row is flagged **⚠** and is worth eyeballing.


## S2016

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `2481` | Division 1 / Summer 2016 | S2016 | none | 34 | **8** | 8 | unchanged | 2016-04-22 .. 2016-07-22 | 26 |
| `2482` | Division 2 / Summer 2016 | S2016 | none | 51 | **12** | 12 | unchanged | 2016-04-22 .. 2016-07-22 | 39 |
| `2483` | Division 3 / Summer 2016 | S2016 | none | 56 | **16** | 16 | unchanged | 2016-04-22 .. 2016-07-22 | 40 |
| `2484` | Division 4  / Summer 2016 | S2016 | none | 51 | **12** | 12 | unchanged | 2016-04-22 .. 2016-07-22 | 39 |
| `2485` | Division 5 / Summer 2016 | S2016 | none | 34 | **8** | 8 | unchanged | 2016-04-22 .. 2016-07-22 | 26 |

## W2016-17

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `5023` | Division 1 / W2016-2017 | W2016-17 | none | 79 | **10** ⚠ | 12 | unchanged | 2016-09-16 .. 2017-03-12 | 69 |
| `5024` | Division 2 / W2016-2017 | W2016-17 | none | 105 | **13** ⚠ | 16 | unchanged | 2016-09-16 .. 2017-03-12 | 92 |
| `5025` | Division 3 / W2016-2017 | W2016-17 | none | 108 | **15** ⚠ | 18 | unchanged | 2016-09-16 .. 2017-03-12 | 93 |
| `5026` | Division 4 / W2016-2017 | W2016-17 | none | 107 | **15** ⚠ | 16 | unchanged | 2016-09-18 .. 2017-03-12 | 92 |
| `5027` | Division 5 / W2016-2017 | W2016-17 | none | 107 | **15** ⚠ | 16 | unchanged | 2016-09-18 .. 2017-03-12 | 92 |

## S2017

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `7481` | Division 1 / S2017 | S2017 | none | 36 | **8** | 8 | unchanged | 2017-04-21 .. 2017-07-28 | 28 |
| `7483` | Division 2 / S2017 | S2017 | none | 55 | **13** ⚠ | 12 | unchanged | 2017-04-21 .. 2017-07-28 | 42 |
| `7484` | Division 5 / S2017 | S2017 | none | 36 | **8** | 8 | unchanged | 2017-04-21 .. 2017-07-28 | 28 |
| `7485` | Division 3 / S2017 | S2017 | none | 54 | **12** | 12 | unchanged | 2017-04-21 .. 2017-07-28 | 42 |
| `7486` | Division 4 / S2017 | S2017 | none | 54 | **12** | 12 | unchanged | 2017-04-21 .. 2017-07-28 | 42 |

## S2018

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `10622` | Division 1 / S2018 | S2018 | 2018-04-27 .. 2018-08-24 | 36 | **8** | 8 | unchanged | 2018-04-27 .. 2018-07-27 | 28 |
| `10623` | Division 2 / S2018 | S2018 | 2018-04-27 .. 2018-08-24 | 36 | **8** | 8 | unchanged | 2018-04-27 .. 2018-07-27 | 28 |
| `10624` | Division 3 / S2018 | S2018 | 2018-04-27 .. 2018-08-24 | 54 | **12** | 12 | unchanged | 2018-04-27 .. 2018-07-27 | 42 |
| `10625` | Division 4 / S2018 | S2018 | 2018-04-27 .. 2018-08-24 | 72 | **16** | 16 | unchanged | 2018-04-27 .. 2018-07-27 | 56 |
| `10626` | Division 5 / S2018 | S2018 | 2018-04-27 .. 2018-08-24 | 36 | **8** | 8 | unchanged | 2018-04-27 .. 2018-07-27 | 28 |

## W2018-19

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `12789` | Division 1 / W2018-19 | W2018-19 + W2018-19 Playoffs | 2018-09-21 .. 2019-04-22 | 54 | **8** | 8 | drop W2018-19 Playoffs (148) | 2018-09-21 .. 2019-03-08 | 46 |
| `12790` | Division 2 / W2018-19 | W2018-19 + W2018-19 Playoffs | 2018-09-21 .. 2019-04-22 | 81 | **12** | 12 | drop W2018-19 Playoffs (148) | 2018-09-21 .. 2019-03-10 | 69 |
| `12791` | Division 3 / W2018-19 | W2018-19 + W2018-19 Playoffs | 2018-09-21 .. 2019-04-22 | 135 | **20** | 20 | drop W2018-19 Playoffs (148) | 2018-09-21 .. 2019-03-10 | 115 |
| `12792` | Division 4 | W2018-19 + W2018-19 Playoffs | 2018-09-21 .. 2019-04-22 | 167 | **28** | 28 | drop W2018-19 Playoffs (148) | 2018-09-21 .. 2019-03-10 | 139 |
| `12793` | Division 5 / W2018-19 | W2018-19 + W2018-19 Playoffs | 2018-09-21 .. 2019-04-22 | 86 | **17** ⚠ | 12 | drop W2018-19 Playoffs (148) | 2018-09-23 .. 2019-03-10 | 69 |

## S2019

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `14984` | Division 4B / S2019 | S2019 + S2019 Playoffs | 2019-04-26 .. 2019-09-13 | 36 | **8** | 8 | drop S2019 Playoffs (157) | 2019-04-26 .. 2019-07-26 | 28 |
| `14985` | Division 4A / S2019 | S2019 + S2019 Playoffs | 2019-04-26 .. 2019-09-13 | 36 | **8** | 8 | drop S2019 Playoffs (157) | 2019-04-26 .. 2019-07-26 | 28 |
| `14986` | Division 3 / S2019 | S2019 + S2019 Playoffs | 2019-04-26 .. 2019-09-13 | 55 | **12** ⚠ | 16 | drop S2019 Playoffs (157) | 2019-04-26 .. 2019-07-26 | 43 |
| `14987` | Division 5 / S2019 | S2019 + S2019 Playoffs | 2019-04-26 .. 2019-09-13 | 37 | **8** ⚠ | 12 | drop S2019 Playoffs (157) | 2019-04-26 .. 2019-07-26 | 29 |
| `14988` | Open Division / S2019 | S2019 + S2019 Playoffs | 2019-04-26 .. 2019-09-13 | 73 | **16** ⚠ | 20 | drop S2019 Playoffs (157) | 2019-04-26 .. 2019-07-26 | 57 |

## W2021-22

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `97389` | Division 5 / W2021-22 | W2021-22 | none | 36 | **8** | 8 | unchanged | 2021-10-15 .. 2022-03-06 | 28 |
| `97390` | Division 4B / W2021-22 | W2021-22 | none | 108 | **24** | 24 | unchanged | 2021-10-15 .. 2022-03-06 | 84 |
| `97391` | Division 4A / W2021-22 | W2021-22 | none | 108 | **24** | 24 | unchanged | 2021-10-15 .. 2022-03-06 | 84 |
| `97392` | Division 3 / W2021-22 | W2021-22 | none | 54 | **12** | 12 | unchanged | 2021-10-15 .. 2022-03-06 | 42 |
| `97393` | Division 2 / W2021-22 | W2021-22 | none | 36 | **8** | 8 | unchanged | 2021-10-17 .. 2022-03-04 | 28 |
| `97394` | Division 1 / W2021-22 | W2021-22 | none | 36 | **8** | 8 | unchanged | 2021-10-17 .. 2022-03-06 | 28 |
| `97518` | ARL Winter 2021-22 / All | W2021-22 + W2021-22 Playoffs | none | 270 | **60** | 60 | drop W2021-22 Playoffs (215) | 2021-10-15 .. 2022-03-06 | 210 |

## S2022

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `100217` | ARL Winter 2021-22 / All | S2022 | none | 160 | **40** | 40 | unchanged | 2022-05-06 .. 2022-07-29 | 120 |
| `100388` | Division 1/2 / S2022 | S2022 | none | 48 | **12** | 12 | unchanged | 2022-05-06 .. 2022-07-29 | 36 |
| `100389` | Division 3 / S2022 | S2022 | none | 32 | **8** | 8 | unchanged | 2022-05-06 .. 2022-07-29 | 24 |
| `100390` | Division 4 / S2022 | S2022 | none | 48 | **12** ⚠ | 14 | unchanged | 2022-05-06 .. 2022-07-29 | 36 |
| `100391` | Division 5 / S2022 | S2022 | none | 32 | **8** ⚠ | 10 | unchanged | 2022-05-06 .. 2022-07-29 | 24 |

## W2022-23

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `102729` | Division D / W2022-23 | W2022-23 | none | 67 | **7** ⚠ | 12 | unchanged | 2022-09-23 .. 2023-03-05 | 60 |
| `102730` | Division C / W2022-23 | W2022-23 | none | 118 | **18** ⚠ | 20 | unchanged | 2022-09-23 .. 2023-03-05 | 100 |
| `102731` | Division B / W2022-23 | W2022-23 | none | 90 | **10** ⚠ | 16 | unchanged | 2022-09-23 .. 2023-03-05 | 80 |
| `102732` | Division A / W2022-23 | W2022-23 | none | 72 | **12** | 12 | unchanged | 2022-09-23 .. 2023-03-05 | 60 |

## W2023-24

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `109246` | Division 1/2 / W2023-24 | W2023-24 | none | 100 | **20** ⚠ | 16 | unchanged | 2023-09-22 .. 2024-02-25 | 80 |
| `109247` | Division 3 / W2023-24 | W2023-24 | 2023-09-21 .. 2024-04-29 | 116 | **16** ⚠ | 20 | unchanged | 2023-09-22 .. 2024-02-25 | 100 |
| `109248` | Division 4/5 / W2023-24 | W2023-24 | 2023-09-21 .. 2024-04-29 | 144 | **24** | 24 | unchanged | 2023-09-22 .. 2024-02-25 | 120 |
| `109798` | Division 4 / W2023-24 | W2023-24 | 2023-09-21 .. 2024-04-29 | 100 | **16** ⚠ | 24 | unchanged | 2023-09-22 .. 2024-02-25 | 84 |
| `109799` | Division 5 / W2023-24 | W2023-24 | 2023-09-21 .. 2024-04-29 | 91 | **8** ⚠ | 24 | unchanged | 2023-09-22 .. 2024-02-25 | 83 |

## S2024

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `110389` | Division 2 / S2024 | S2024 | none | 36 | **8** | 8 | unchanged | 2024-04-19 .. 2024-07-26 | 28 |
| `110391` | Division 3 / S2024 | S2024 | none | 36 | **8** | 8 | unchanged | 2024-04-19 .. 2024-07-26 | 28 |
| `110392` | Division 1 / S2024 | S2024 | none | 36 | **8** | 8 | unchanged | 2024-04-19 .. 2024-07-26 | 28 |
| `110393` | Division 5 / S2024 | S2024 | none | 72 | **16** | 16 | unchanged | 2024-04-19 .. 2024-07-26 | 56 |
| `110394` | Division 4 / S2024 | S2024 | none | 72 | **16** | 16 | unchanged | 2024-04-19 .. 2024-07-26 | 56 |

## W2024-25

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `111470` | Division 1 / W2024-25 | W2024-25 | 2024-10-03 .. 2025-04-25 | 72 | **12** | 12 | unchanged | 2024-10-04 .. 2025-02-23 | 60 |
| `111471` | Division 2 / W2024-25 | W2024-25 | 2024-10-03 .. 2025-04-25 | 68 | **8** ⚠ | 12 | unchanged | 2024-10-04 .. 2025-02-21 | 60 |
| `111472` | Division 3 / W2024-25 | W2024-25 | 2024-10-03 .. 2025-04-25 | 173 | **33** ⚠ | 28 | unchanged | 2024-10-04 .. 2025-02-23 | 140 |
| `111473` | Division 4 / W2024-25 | W2024-25 | 2024-10-03 .. 2025-04-25 | 173 | **33** ⚠ | 28 | unchanged | 2024-10-04 .. 2025-02-23 | 140 |
| `111474` | Division 5 / W2024-25 | W2024-25 | 2024-10-03 .. 2025-04-25 | 47 | **7** ⚠ | 8 | unchanged | 2024-10-06 .. 2025-02-23 | 40 |

## S2025

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `112924` | Division 4 / S2025 | S2025 | 2025-04-24 .. 2025-09-29 | 51 | **9** ⚠ | 12 | unchanged | 2025-04-25 .. 2025-07-25 | 42 |
| `112926` | Division 5 / S2025 | S2025 | 2025-04-18 .. 2025-09-29 | 34 | **6** ⚠ | 8 | unchanged | 2025-04-25 .. 2025-07-25 | 28 |
| `112928` | Division 1 / S2025 | S2025 | 2025-04-18 .. 2025-09-29 | 34 | **6** ⚠ | 8 | unchanged | 2025-04-25 .. 2025-07-25 | 28 |
| `112930` | Division 3 / S2025 | S2025 | 2025-04-18 .. 2025-09-29 | 34 | **6** ⚠ | 8 | unchanged | 2025-04-25 .. 2025-07-25 | 28 |
| `112932` | Division 2 / S2025 | S2025 | 2025-04-18 .. 2025-09-29 | 34 | **6** ⚠ | 8 | unchanged | 2025-04-25 .. 2025-07-25 | 28 |

## W2025-26

| Table | Name | Seasons attached | Current range | Now | Drop | Exp | Proposed seasons | Proposed range | After |
|---|---|---|---|---:|---:|---:|---|---|---:|
| `114393` | Division 1 / W2025-26 | W2025-26 | none | 146 | **28** ⚠ | 24 | unchanged | 2025-09-26 .. 2026-03-01 | 118 |
| `114394` | Division 2 / W2025-26 | W2025-26 | none | 146 | **28** ⚠ | 24 | unchanged | 2025-09-26 .. 2026-03-01 | 118 |
| `114395` | Division 3 / W2025-26 | W2025-26 | none | 78 | **18** ⚠ | 12 | unchanged | 2025-09-26 .. 2026-03-01 | 60 |
| `114396` | Division 4 / W2025-26 | W2025-26 | none | 98 | **20** ⚠ | 16 | unchanged | 2025-09-26 .. 2026-03-01 | 78 |
| `114397` | Division 5 / W2025-26 | W2025-26 | none | 72 | **12** | 12 | unchanged | 2025-09-26 .. 2026-03-01 | 60 |

---

**71 tables.** 5339 events matched today, of which **1009 playoff games** would be removed, leaving 4330.

## Rows to eyeball before applying (32)

These have more or fewer playoff games than 4-per-team predicts. Most are explained by playoff
groupings that span divisions (a division table matches playoff games from several groups), or
by divisions merging/splitting mid-season — but each is worth a glance.

| Table | Name | Playoff games found | Expected (4/team) | Teams |
|---|---|---:|---:|---:|
| `5023` | Division 1 / W2016-2017 | 10 | 12 | 6 |
| `5024` | Division 2 / W2016-2017 | 13 | 16 | 8 |
| `5025` | Division 3 / W2016-2017 | 15 | 18 | 9 |
| `5026` | Division 4 / W2016-2017 | 15 | 16 | 8 |
| `5027` | Division 5 / W2016-2017 | 15 | 16 | 8 |
| `7483` | Division 2 / S2017 | 13 | 12 | 6 |
| `12793` | Division 5 / W2018-19 | 17 | 12 | 6 |
| `14986` | Division 3 / S2019 | 12 | 16 | 8 |
| `14987` | Division 5 / S2019 | 8 | 12 | 6 |
| `14988` | Open Division / S2019 | 16 | 20 | 10 |
| `100390` | Division 4 / S2022 | 12 | 14 | 7 |
| `100391` | Division 5 / S2022 | 8 | 10 | 5 |
| `102729` | Division D / W2022-23 | 7 | 12 | 6 |
| `102730` | Division C / W2022-23 | 18 | 20 | 10 |
| `102731` | Division B / W2022-23 | 10 | 16 | 8 |
| `109246` | Division 1/2 / W2023-24 | 20 | 16 | 8 |
| `109247` | Division 3 / W2023-24 | 16 | 20 | 10 |
| `109798` | Division 4 / W2023-24 | 16 | 24 | 12 |
| `109799` | Division 5 / W2023-24 | 8 | 24 | 12 |
| `111471` | Division 2 / W2024-25 | 8 | 12 | 6 |
| `111472` | Division 3 / W2024-25 | 33 | 28 | 14 |
| `111473` | Division 4 / W2024-25 | 33 | 28 | 14 |
| `111474` | Division 5 / W2024-25 | 7 | 8 | 4 |
| `112924` | Division 4 / S2025 | 9 | 12 | 6 |
| `112926` | Division 5 / S2025 | 6 | 8 | 4 |
| `112928` | Division 1 / S2025 | 6 | 8 | 4 |
| `112930` | Division 3 / S2025 | 6 | 8 | 4 |
| `112932` | Division 2 / S2025 | 6 | 8 | 4 |
| `114393` | Division 1 / W2025-26 | 28 | 24 | 12 |
| `114394` | Division 2 / W2025-26 | 28 | 24 | 12 |
| `114395` | Division 3 / W2025-26 | 18 | 12 | 6 |
| `114396` | Division 4 / W2025-26 | 20 | 16 | 8 |

Seasons that came back clean and are not listed: S2023, W2017-18, W2019-20, W2020-21 (the
COVID-affected winters), S2026 (fixed 2026-08-12) and W2026-27 (no playoff term yet).

