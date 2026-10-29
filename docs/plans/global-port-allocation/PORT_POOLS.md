# Proposed Port Pools

Pools are grouped by preferred/default base port, not by game family. Family labels are convenience names only. Multiple unrelated games can share a pool when their defaults are equal or close enough to fit the same 10-port block policy.

| Pool name | Default/base port | Range start | Range end | Block size | Capacity per IP | Assigned/review games | Collision notes | Fallback/overflow behavior | Source/confidence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `arma-dayz-2302` | 2302 | 2302 | 2399 | 10 | 9 blocks | Arma, DayZ, DayZ Mod/Reforger review set | Keep Arma-family offsets inside +0..+9 where possible; DayZ XML uses query +6 and RCON +3. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | repo-protocol for DayZ; other Arma rows need external verification |
| `valheim-2456` | 2456 | 2456 | 2525 | 10 | 7 blocks | Valheim Linux and modded Valheim review set | Planned from user/product requirement; verify current vendor defaults before implementation. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | needs external verification |
| `unreal-7777` | 7777 | 7777 | 7866 | 10 | 9 blocks | Unreal/ARK/Conan/KF2/Mordhau/Sandstorm-style review set | Do not create a conflicting nearby pool for defaults like 7775 when a 7777 block model already covers the neighborhood; map as adjusted-to-pool or flag review. Many UE games have external query offsets such as +19238 and are non-standard. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | repo-protocol for LGSL defaults; many XML rows need external verification |
| `minecraft-25565` | 25565 | 25565 | 25654 | 10 | 9 blocks | Minecraft, Spigot, Feed the Beast review set | Minecraft rows should use game +0 and RCON/query offsets from XML where present; verify edition-specific query/RCON defaults. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | needs external verification |
| `7dtd-26900` | 26900 | 26900 | 27009 | 10 | 11 blocks | 7 Days to Die review set | Capped before Source/SRCDS. The earlier conclusion stands: 26900 to 27015 leaves only 11 full 10-port allocations before collision if the next pool starts at 27015. Do not let this pool run into 27015. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | needs external verification |
| `source-27015` | 27015 | 27015 | 27104 | 10 | 9 blocks | Source/SRCDS, Counter-Strike, TF2, Garry's Mod, Unturned/The Forest review set | Keep distinct from 26900. Source-compatible games usually fit +0 but validate query/RCON behavior per XML. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | repo-protocol for some LGSL/GameQ metadata; many XML rows need external verification |
| `rust-28015` | 28015 | 28015 | 28104 | 10 | 9 blocks | Rust review set | User-required Rust-style pool. Current repo LGSL metadata has legacy Rust default ambiguity, so verify production Rust defaults and Windows XML query +10 conflict. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | needs external verification |
| `quake-cod-28960` | 28960 | 28960 | 29049 | 10 | 9 blocks | Quake/Call of Duty/Jedi/Urban Terror/Warsow review set | Call of Duty rows without protocol metadata should be verified before final mapping; Quake-style defaults are convenience grouping, not family policy. | Preferred first; then explicit compatible pool; then `gse-overflow-32000`; then fail with no available blocks for that IP. | needs external verification with some repo-protocol support |
| `gse-overflow-32000` | 32000 | 32000 | 32999 | 10 | 100 blocks | All games after preferred/compatible pools are full on an IP | Controlled overflow only. This is a 1000-port range, not 1000 server allocations. At 10 ports per block it provides 100 blocks per IP. | Last controlled fallback after preferred and compatible pools are full; fail after 100 blocks per IP. | product policy |

## Capacity Rules

- Capacity per IP is `floor((range_end - range_start + 1) / block_size)` for 10-port aligned pools.

- A normal allocation owns `base+0` through `base+9`.

- The future database should store whole-block ownership in `gsp_home_port_blocks`, not only the selected primary/base port. `gsp_home_ip_ports` should still receive the primary/base port as a compatibility write.

- Static service reservations reduce available capacity on the specific IP/host where they apply.

- Static service reservations should participate in the same no-overlap model as allocated game blocks. The recommended default for public services is reserving the containing 10-port block; single-port reservations can be allowed only after deployment review.

- Pool ranges should be capped before the next nearby pool. The `26900` pool must not drift into `27015`; `26900-27009` gives 11 blocks and leaves `27010-27014` as gap/review space.

- Do not use `40000-59999` as the default overflow range. Verify OS ephemeral and FTP passive ranges during implementation before any high-port pool is approved.


## Mapping Rules

- If a game default would create an overlapping pool, map it to the nearest compatible pool and mark it `adjusted-to-pool` with the reason. Example: a game defaulting to 7775 should not create a conflicting 7775 pool if the 7777 pool owns the neighborhood.

- If XML reserve/query offsets exceed `+9` or use large negative offsets, mark the game `non-standard` and require a per-game decision.

- Pool priority is preferred, compatible, overflow, fail.

- Game-to-pool assignments should be represented in the proposed `gsp_port_pool_games` table. Per-game primary/query/RCON/web/Steam/admin offsets should be represented in the proposed `gsp_game_port_offsets` table after XML import/review.

- Overflow is the explicit `gse-overflow-32000` pool only. It is used after preferred and compatible pools are exhausted and provides 100 10-port blocks per IP.
