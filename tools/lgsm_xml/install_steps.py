"""Extra install steps that LinuxGSM runs outside its config files.

LinuxGSM keeps some install work in its modules (install_server_files.sh,
update_*.sh and fix_*.sh). Those steps are copied here as short bash so the
generated XML can do the same work.

Install scripts run in the game folder (the panel changes into it first).
They are written to the XML inside CDATA, so characters such as < and & are fine.
"""

# Unpacks a downloaded archive (zip or any tar format) into the current folder.
UNPACK = """unpack() {
  if unzip -tq "$1" > /dev/null 2>&1; then unzip -oq "$1"; else tar -xf "$1"; fi
  rm -f "$1"
}"""

# Prints the download link of the first asset of the latest GitHub release
# whose file name contains the given text.
GITHUB_ASSET = """github_asset() {
  curl -fsSL "https://api.github.com/repos/$1/releases/latest" | python3 -c '
import json, sys
wanted = sys.argv[1]
for asset in json.load(sys.stdin)["assets"]:
    if wanted in asset["name"]:
        print(asset["browser_download_url"])
        break
' "$2"
}"""


def archive_from(url):
    """Download one archive and unpack it into the game folder."""
    return f"""{UNPACK}
curl -fL --retry 3 -o gse-download "{url}"
unpack gse-download"""


def github_release(repo, name_part):
    """Download the newest GitHub release asset and unpack it."""
    return f"""{UNPACK}
{GITHUB_ASSET}
url="$(github_asset {repo} "{name_part}")"
curl -fL --retry 3 -o gse-download "$url"
unpack gse-download"""


def papermc(project, jar_name):
    """Download the newest build of a PaperMC project (paper, velocity, waterfall)."""
    return f"""api="https://fill.papermc.io/v3/projects/{project}"
version="$(curl -fsSL "$api" | python3 -c 'import json, sys; groups = json.load(sys.stdin)["versions"]; print(next(iter(groups.values()))[0])')"
build="$(curl -fsSL "$api/versions/$version" | python3 -c 'import json, sys; print(json.load(sys.stdin)["builds"][0])')"
url="$(curl -fsSL "$api/versions/$version/builds/$build" | python3 -c 'import json, sys; print(json.load(sys.stdin)["downloads"]["server:default"]["url"])')"
curl -fL --retry 3 -o {jar_name} "$url"
"""


LEGACY = "https://linuxgsm.download"

# Download steps for games that are not (only) on Steam.
# Games with a Steam app id get these steps after SteamCMD finishes.
DOWNLOADS = {
    "ahl": archive_from(f"{LEGACY}/ActionHalfLife/ahl-1.0-linux-i386-linuxgsm-20261102.tar.xz"),
    "bf1942": archive_from(f"{LEGACY}/BattleField1942/bf1942-1.612-linux-i386-linuxgsm-20261101.tar.xz"),
    "bfv": archive_from(f"{LEGACY}/BattlefieldVietnam/bfv-1.21-linux-i386-linuxgsm-20261101.tar.xz"),
    "cswon": archive_from(f"{LEGACY}/CounterStrikeWON2/cswon-3111e-cs15-linux-i386-linuxgsm-20261104.tar.xz"),
    "hlwon": archive_from(f"{LEGACY}/HalfLifeWON2/hlwon-3111e-linux-i386-linuxgsm-20261104.tar.xz"),
    "ns": archive_from(f"{LEGACY}/NaturalSelection/ns-3.2-linux-i386-linuxgsm-20261102.tar.xz"),
    "q2": archive_from(f"{LEGACY}/Quake2/q2-3.20-linux-i386-linuxgsm-20261102.tar.xz"),
    "sfc": archive_from(f"{LEGACY}/SourceFortsClassic/sfc-1.0-rc7-fix-linux-i386-linuxgsm-20261102.tar.xz"),
    "sof2": archive_from(f"{LEGACY}/SoldierOfFortune2/sof2-1.03-linux-i386-linuxgsm-20261101.tar.xz"),
    "ts": archive_from(f"{LEGACY}/TheSpecialists/ts-3.0-linux-i386-linuxgsm-20261102.tar.xz"),
    "ut": archive_from(f"{LEGACY}/UnrealTournament/ut-xan-3525360-linux-amd64-linuxgsm-20261102.tar.xz"),
    "vs": archive_from(f"{LEGACY}/VampireSlayer/vs-6.0-linux-i386-linuxgsm-20261102.tar.xz"),
    "zmr": archive_from(f"{LEGACY}/ZombieMasterReborn/zmr-b6.1-linux-i386-linuxgsm-20261102.tar.xz"),
    "bb": github_release("IronOak-Studios/BrainBread", "linuxserver.tar.gz"),
    "etl": github_release("GameServerManagers/etlserver-build", "i386-et-260b"),
    "luanti": github_release("rollerozxa/luantiserver", "x86_64.tar.gz"),
    "qw": github_release("GameServerManagers/qwserver-build", ".full.tar.xz"),
    "pmc": papermc("paper", "paper.jar"),
    "vpmc": papermc("velocity", "velocity.jar"),
    "wmc": papermc("waterfall", "waterfall.jar"),
    "mcb": f"""{UNPACK}
url="$(curl -fsSL https://net-secondary.web.minecraft-services.net/api/v1.0/download/links | python3 -c '
import json, sys
for link in json.load(sys.stdin)["result"]["links"]:
    if link["downloadType"] == "serverBedrockLinux":
        print(link["downloadUrl"])
')"
# Keep the settings files when updating; the zip contains fresh copies of them.
mkdir -p gse-keep
for file in server.properties permissions.json allowlist.json; do
  if [ -f "$file" ]; then cp "$file" gse-keep/; fi
done
curl -fL --retry 3 -o gse-download "$url"
unpack gse-download
cp gse-keep/* . 2> /dev/null || true
rm -rf gse-keep""",
    "vints": f"""{UNPACK}
url="$(curl -fsSL http://api.vintagestory.at/stable-unstable.json | python3 -c '
import json, sys
releases = json.load(sys.stdin)
stable = [name for name in releases if "-rc" not in name and "-pre" not in name]
newest = max(stable, key=lambda name: [int(part) for part in name.split(".")])
print(releases[newest]["linuxserver"]["urls"]["cdn"])
')"
curl -fL --retry 3 -o gse-download "$url"
unpack gse-download""",
    "orar": f"""{GITHUB_ASSET}
url="$(github_asset OpenRA/OpenRA "Red-Alert-x86_64.AppImage")"
curl -fL --retry 3 -o openra.AppImage "$url"
chmod +x openra.AppImage
./openra.AppImage --appimage-extract > /dev/null
rm -rf openra
mv squashfs-root openra
rm -f openra.AppImage""",
    "bfe": f"""{UNPACK}
# Node.js runtime (LinuxGSM update_bfe.sh uses the latest v24 release).
node_file="$(curl -fsSL https://nodejs.org/dist/latest-v24.x/SHASUMS256.txt | grep -o 'node-v[0-9.]*-linux-x64.tar.xz' | head -n 1)"
curl -fL --retry 3 -o node.tar.xz "https://nodejs.org/dist/latest-v24.x/$node_file"
rm -rf node && mkdir node
tar -xf node.tar.xz -C node --strip-components=1
rm -f node.tar.xz
# Battlefront Eternal server files.
server_file="$(curl -fsSL https://get.battlefronteternal.com/downloads/latest/SHA256SUMS | grep -o 'battlefront-eternal-server_[^ ]*_linux-x64.tar.xz' | head -n 1)"
mkdir -p bfe && cd bfe
curl -fL --retry 3 -o gse-download "https://get.battlefronteternal.com/downloads/latest/$server_file"
unpack gse-download""",
    "hyt": f"""{UNPACK}
# Hytale server files need a signed-in Hytale account, so they cannot be
# downloaded without a person. This gets the official downloader only.
curl -fL --retry 3 -o hytale-downloader.zip https://downloader.hytale.com/hytale-downloader.zip
unzip -oq hytale-downloader.zip hytale-downloader-linux-amd64 QUICKSTART.md
rm -f hytale-downloader.zip
chmod +x hytale-downloader-linux-amd64
echo "Hytale: run ./hytale-downloader-linux-amd64 once in this folder and sign in to download the server files." """,
}

# Commands from LinuxGSM fix_*.sh that change files after install.
FIXES = {
    "bt": 'mkdir -p "$HOME/.local/share/Daedalic Entertainment GmbH/Barotrauma"',
    "cmw": "echo 219640 > Binaries/Linux/steam_appid.txt",
    "sfc": "\n".join(
        f"[ -e bin/{name}.so ] || ln -s {name}_srv.so bin/{name}.so"
        for name in ("datacache", "dedicated", "engine", "materialsystem", "replay",
                     "scenefilecache", "shaderapiempty", "soundemittersystem", "studiorender", "vphysics")
    ),
}
FIXES["zmr"] = FIXES["sfc"]

# Folders where LinuxGSM fix_steamcmd.sh copies steamclient.so for one game.
# (bits, folder relative to the game folder)
STEAMCLIENT_COPIES = {
    "bo": [("32", "BODS_Data/Plugins/x86"), ("64", "BODS_Data/Plugins/x86_64")],
    "cmw": [("32", "Binaries/Linux/lib")],
    "pvr": [("64", "Pavlov/Binaries/Linux")],
    "pz": [("32", "linux32"), ("64", "linux64")],
    "tu": [("64", "Tower/Binaries/Linux")],
}

# Environment lines from LinuxGSM fix_*.sh. The agent replaces {GAME_HOME_DIR}
# with the game folder before the server starts.
EXEC_STACK = "export GLIBC_TUNABLES=glibc.rtld.execstack=2"
OLD_CPU = "export GLIBC_TUNABLES=glibc.cpu.hwcaps=-Fast_Unaligned_Load,-SSSE3"
LIB_GAME = "export LD_LIBRARY_PATH=$LD_LIBRARY_PATH:{GAME_HOME_DIR}"
LIB_LINUX64 = "export LD_LIBRARY_PATH=$LD_LIBRARY_PATH:{GAME_HOME_DIR}:{GAME_HOME_DIR}/linux64"
ENVIRONMENT = {
    "ahl": EXEC_STACK, "sfc": EXEC_STACK, "ts": EXEC_STACK, "vs": EXEC_STACK, "ut": EXEC_STACK,
    "cswon": OLD_CPU, "hlwon": OLD_CPU,
    "bf1942": LIB_GAME, "bfv": LIB_GAME, "mcb": LIB_GAME, "sof2": LIB_GAME,
    "pvr": LIB_LINUX64, "rw": LIB_LINUX64, "sm": LIB_LINUX64,
    "bo": "export LD_LIBRARY_PATH=$LD_LIBRARY_PATH:{GAME_HOME_DIR}:{GAME_HOME_DIR}/BODS_Data/Plugins/x86_64",
}

# Executables that must not be taken from LinuxGSM as written.
EXECUTABLE_OVERRIDES = {
    # LinuxGSM picks the script with a shell test; GSE agents are 64-bit.
    "ql": "./run_server_x64.sh",
    # The agent starts files ending in .exe with Wine. Barotrauma's Linux
    # server is the native ./DedicatedServer (Steam's Linux launch entry).
    "bt": "./DedicatedServer",
    # LinuxGSM writes the full path; the agent wants a name in the run folder.
    "mcb": "./bedrock_server",
}

# Start options added to LinuxGSM's line. These games take their port only from
# their config file in LinuxGSM; the options below let the panel's port win.
START_ADDITIONS = {
    "etl": "+set net_ip ${ip} +set net_port ${port}",
    "ql": "+set net_ip ${ip} +set net_port ${port}",
    "pz": "-port ${port} -udpport ${udpport}",
    "pmc": "--port ${port}",
}
# Values the additions need that LinuxGSM's config does not have.
EXTRA_VALUES = {
    "pz": {"udpport": "16262"},
    "pmc": {"port": "25565"},
    # LinuxGSM copies its own config to cfg/; DayZ ships serverDZ.cfg in the game folder.
    "dayz": {"servercfgfullpath": "${serverfiles}/serverDZ.cfg"},
}

# Start options the server owner must switch on themselves, such as a EULA.
# They are taken out of LinuxGSM's line and offered as a tick box instead.
OWNER_CHOICES = {
    "scpsl": {"key": "--acceptEULA", "caption": "Accept the SCP: Secret Laboratory EULA",
              "desc": "The server owner must accept the game's EULA before the server can start."},
}

# Games whose Steam Windows launch entry is the game client, not the server.
# They get no Windows block until the right executable is known.
WINDOWS_SKIP = {
    "nec": "Steam's Windows entry is javaw.exe with no server jar.",
    "rwr": "Steam's Windows entry is rwr_game.exe, the game client.",
    "tw": "Steam's Windows entry is teeworlds.exe, the game client.",
}

# Notes written at the top of a generated XML.
NOTES = {
    "ahl": "LinuxGSM also installs ReHLDS for this mod (fix_ahl.sh); that step is not automated here.",
    "bf1942": "Needs the 32-bit libncurses5 library on the host (LinuxGSM fix_bf1942.sh).",
    "bfv": "Needs the 32-bit libncurses5 library on the host (LinuxGSM fix_bfv.sh).",
    "bfe": "Needs a Steam account that owns Star Wars Battlefront (Classic, 2004); set it in the panel's Steam settings.",
    "ck": "Needs xvfb-run on the host (Ubuntu package xvfb).",
    "hyt": "Server files need a signed-in Hytale account: run the downloader once after install.",
    "pvr": "Needs libc++ on the host if PavlovServer reports libc++.so.1 missing (LinuxGSM fix_pvr.sh).",
    "pmc": "The server owner must accept the Minecraft EULA (eula=true in eula.txt) before the first start.",
    "tf2c": "Uses Team Fortress 2 server files from the tf2 folder (-tf_path). If the server reports missing TF2 content, install Steam app 232250 into tf2/.",
}
