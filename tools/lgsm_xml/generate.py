#!/usr/bin/env python3
"""Write GSE game XML files from LinuxGSM's game configs.

Usage:
  python3 tools/lgsm_xml/generate.py LINUXGSM_DIR STEAM_INFO_JSON OUTPUT_DIR

LINUXGSM_DIR     a checkout of https://github.com/GameServerManagers/LinuxGSM
STEAM_INFO_JSON  Steam app info by app id (made by steam_info.py); gives the
                 Windows executable for games that also run on Windows
OUTPUT_DIR       where the new XML files go (normally modules/config_games/server_configs)

Only games listed in game_list.NEW and extra_games.EXTRAS are written.
Existing XML files are never overwritten by this script.

How a LinuxGSM start line becomes a GSE start line:
  ${port}, ${ip}, ${maxplayers}, ${servername}  -> panel values (%PORT% ...)
  other ${...port}                             -> extra ports, offset from the game port
  ${...password} (not the join password)       -> %CONTROL_PASSWORD%
  ${serverfiles} and other folders             -> %HOME_PATH% (the game folder)
  "-flag ${setting}" or "-flag=${setting}"     -> a setting the customer can change
  anything else                                -> LinuxGSM's default value, written in
"""
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from game_list import NEW  # noqa: E402
from extra_games import EXTRAS  # noqa: E402
import install_steps  # noqa: E402

ASSIGN = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)="(.*?)"\s*(?:#\s*(.*))?$')
VARIABLE = re.compile(r"\$\{([A-Za-z_][A-Za-z0-9_]*)\}")

# Folders LinuxGSM builds its paths from, and what they are on a GSE agent.
FOLDERS = {
    "serverfiles": "%HOME_PATH%",
    "rootdir": "%HOME_PATH%",
    "lgsmdir": "%HOME_PATH%/gse",
    "logdir": "%HOME_PATH%/logs",
    "tmpdir": "%HOME_PATH%/tmp",
}
PANEL_VALUES = {"port": "PORT", "ip": "IP", "publicip": "IP", "maxplayers": "PLAYERS", "servername": "HOSTNAME"}
NOT_A_CONTROL_PASSWORD = {"serverpassword", "betapassword", "steampass"}
CAPTIONS = {
    "gslt": "Steam Game Server Login Token",
    "serverpassword": "Join password",
    "gamemode": "Game mode",
    "game_mode": "Game mode",
    "game_type": "Game type",
    "gametype": "Game type",
    "gameid": "Game (subgame) to run",
    "tickrate": "Tick rate",
    "worldname": "World name",
    "javaram": "Java memory",
    "wsapikey": "Steam Web API key (Workshop)",
    "steamsocket": "Use Steam sockets",
    "saveinterval": "Save interval (seconds)",
    "backupinterval": "Backup interval (minutes)",
    "region": "Server region",
    "maxqueuesize": "Join queue size",
    "queuevalidtime": "Join queue hold time",
    "httpuser": "Web admin user name",
    "greenhand": "New player protection",
    "damageself": "Self damage",
    "damageallies": "Friendly fire",
    "anticheat": "Easy Anti-Cheat",
    "allowsensitivewords": "Allow sensitive words in chat",
    "adminlabel": "Show admin title",
    "eosclientid": "Epic Online Services client id",
    "eosclientsecret": "Epic Online Services client secret",
    "mods": "Mods (folders, separated by ;)",
    "servermods": "Server-side mods (folders, separated by ;)",
    "bepath": "BattlEye folder",
}


# ---------- reading LinuxGSM ----------

def read_cfg(path):
    """Read key="value" lines. Returns {name: (value, comment)}."""
    values = {}
    for raw in path.read_text(errors="replace").splitlines():
        match = ASSIGN.match(raw.strip())
        if match:
            value = match.group(2).replace('\\"', '"')
            values[match.group(1)] = (value, (match.group(3) or "").strip())
    return values


def cfg_value(cfg, name):
    return cfg.get(name, ("", ""))[0]


# ---------- turning LinuxGSM values into GSE values ----------

class Conversion:
    """Collects the panel values, ports and settings a start line uses."""

    def __init__(self, cfg, use_map_list):
        self.cfg = cfg
        self.use_map_list = use_map_list
        self.cli_params = []      # ids such as PORT, IP
        self.ports = {}           # id -> offset from the game port
        self.settings = []        # server_params entries

    def use_cli(self, param_id):
        if param_id not in self.cli_params:
            self.cli_params.append(param_id)
        return f"%{param_id}%"

    def is_port(self, name):
        return name.endswith("port") and name != "port" and cfg_value(self.cfg, name).isdigit()

    def is_control_password(self, name):
        return "password" in name and name not in NOT_A_CONTROL_PASSWORD

    def value_of(self, name):
        """The GSE text for one LinuxGSM variable (panel value, port or written-in default)."""
        if name in PANEL_VALUES:
            return self.use_cli(PANEL_VALUES[name])
        if name in FOLDERS:
            if "%HOME_PATH%" in FOLDERS[name]:
                self.use_cli("HOME_PATH")
            return FOLDERS[name]
        if name == "selfname":
            return "gse"
        if self.is_port(name):
            return self.port_value(name)
        if self.is_control_password(name):
            return self.use_cli("CONTROL_PASSWORD")
        if name == "defaultmap" and self.use_map_list:
            return self.use_cli("MAP")
        if name == "servercfg" and "${selfname}" in cfg_value(self.cfg, "servercfg"):
            # LinuxGSM names the config after its script; GSE uses the game's own file name.
            return self.resolve(cfg_value(self.cfg, "servercfgdefault"))
        return self.resolve(cfg_value(self.cfg, name))

    def port_value(self, name):
        base = cfg_value(self.cfg, "port")
        if not base.isdigit():
            # No game port in LinuxGSM (for example Battlefront Eternal's healthport):
            # the first port used becomes the panel's game port.
            self.cfg["port"] = (cfg_value(self.cfg, name), "")
            base = cfg_value(self.cfg, name)
        offset = int(cfg_value(self.cfg, name)) - int(base)
        if offset == 0:
            return self.use_cli("PORT")
        port_id = name.upper()
        self.ports[port_id] = offset
        return f"%{port_id}%"

    def resolve(self, text):
        return VARIABLE.sub(lambda match: self.value_of(match.group(1)), text)

    def is_setting(self, name):
        """A value the customer may want to change, such as a token or a game mode."""
        if name in PANEL_VALUES or name in FOLDERS or name in ("selfname", "defaultmap", "servercfg"):
            return False
        if self.is_port(name) or self.is_control_password(name) or name not in self.cfg:
            return False
        # A default built from other values (often a folder) is written in, because the
        # panel does not replace %HOME_PATH% inside a customer setting.
        return "${" not in cfg_value(self.cfg, name)

    def add_setting(self, name, key, options):
        value, comment = self.cfg[name]
        self.settings.append({
            "id": name.upper(), "key": key, "options": options, "default": self.resolve(value),
            "caption": CAPTIONS.get(name, name), "desc": comment,
        })
        return f"%{name.upper()}%"


def split_tokens(text):
    """Split a start line on spaces, keeping quoted parts together."""
    tokens, current, in_quotes = [], "", False
    for char in text:
        if char == '"':
            in_quotes = not in_quotes
        if char == " " and not in_quotes:
            if current:
                tokens.append(current)
            current = ""
        else:
            current += char
    if current:
        tokens.append(current)
    return tokens


def is_flag(token):
    return token[:1] in "-+" and "${" not in token and "=" not in token and "%" not in token


def convert_start_line(text, conversion):
    """Turn a LinuxGSM start line into a GSE cli_template."""
    # LinuxGSM's own config file is named after its script; use the game's config name.
    text = text.replace("${selfname}.cfg", "${servercfg}")
    output = []
    for token in split_tokens(text):
        names = VARIABLE.findall(token)
        name = names[0] if len(names) == 1 else ""
        if name == "javaram" and token == "-Xmx${javaram}M":
            conversion.cfg["javaram"] = (cfg_value(conversion.cfg, "javaram") + "M", "Maximum Java heap, for example 2048M")
            output.append(conversion.add_setting("javaram", "-Xmx", "ns"))
            continue
        if name and conversion.is_setting(name):
            quoted = token == '"${' + name + '}"'
            if (token == "${" + name + "}" or quoted) and output and is_flag(output[-1]):
                flag = output.pop()
                output.append(conversion.add_setting(name, flag, "" if quoted else "s"))
                continue
            flag_match = re.fullmatch(r'([-+][^=\s"]+=)("?)\$\{' + name + r'\}("?)', token)
            if flag_match:
                options = "q" if flag_match.group(2) else "ns"
                output.append(conversion.add_setting(name, flag_match.group(1), options))
                continue
        output.append(conversion.resolve(token))
    return " ".join(part for part in output if part != "")


def game_folder_of(start_line):
    """The folder named by -game (Source and GoldSrc games), or ''."""
    match = re.search(r"-game\s+(\S+)", start_line)
    return match.group(1) if match else ""


def relative_to_game(path_text):
    """'%HOME_PATH%/a/b' -> 'a/b'. '%HOME_PATH%' -> ''."""
    return path_text.replace("%HOME_PATH%", "").strip("/")


# ---------- building one game ----------

def steam_client_script(short_name):
    """Same as LinuxGSM fix_steamcmd.sh: make steamclient.so findable."""
    lines = [
        "# steamclient.so for the Steam API (LinuxGSM fix_steamcmd.sh does the same).",
        "for bits in 64 32; do",
        '  lib="$(find . "$HOME/.steam" "$HOME/.local/share/Steam" -maxdepth 4 -path "*linux$bits/steamclient.so" 2> /dev/null | head -n 1)"',
        '  if [ -z "$lib" ]; then lib="$(find "$HOME" -maxdepth 5 -path "*steamcmd/linux$bits/steamclient.so" 2> /dev/null | head -n 1)"; fi',
        '  if [ -n "$lib" ]; then',
        '    mkdir -p "$HOME/.steam/sdk$bits"',
        '    ln -sf "$(readlink -f "$lib")" "$HOME/.steam/sdk$bits/steamclient.so"',
        "  fi",
        "done",
    ]
    for bits, folder in install_steps.STEAMCLIENT_COPIES.get(short_name, []):
        lines.append(f'mkdir -p "{folder}" && cp -f "$HOME/.steam/sdk{bits}/steamclient.so" "{folder}/steamclient.so" 2> /dev/null || true')
    return "\n".join(lines)


def start_line_for(short_name, cfg):
    """LinuxGSM's start line with our additions and owner choices applied."""
    for name, value in install_steps.EXTRA_VALUES.get(short_name, {}).items():
        cfg[name] = (value, "")
    start_line = cfg_value(cfg, "startparameters")
    if short_name in install_steps.START_ADDITIONS:
        start_line = f"{start_line} {install_steps.START_ADDITIONS[short_name]}".strip()
    choice = install_steps.OWNER_CHOICES.get(short_name)
    if choice:
        start_line = " ".join(token for token in start_line.split(" ") if token != choice["key"])
    return start_line


def linux_block(short_name, cfg):
    """The <linux> settings for one LinuxGSM game."""
    start_line = start_line_for(short_name, cfg)
    engine = cfg_value(cfg, "engine")
    # Folder lookups use their own Conversion so they do not add unused panel values.
    paths = Conversion(cfg, False)
    game_folder = relative_to_game(paths.resolve(game_folder_of(start_line)).strip('"'))
    use_map_list = engine in ("source", "goldsrc") and bool(game_folder) and "${defaultmap}" in start_line
    conversion = Conversion(cfg, use_map_list)
    choice = install_steps.OWNER_CHOICES.get(short_name)
    if choice:
        conversion.settings.append(dict(choice, type="checkbox_key_value"))

    executable = install_steps.EXECUTABLE_OVERRIDES.get(short_name, cfg_value(cfg, "executable"))
    executable = executable.replace("${serverfiles}/", "./")
    run_folder = relative_to_game(paths.resolve(cfg_value(cfg, "executabledir")))
    pre_executable = cfg_value(cfg, "preexecutable")

    template = convert_start_line(start_line, conversion)
    block = {"exe": executable[2:] if executable.startswith("./") else executable,
             "exe_location": run_folder}
    if pre_executable:
        # The agent always runs ./<exe>. A tiny wrapper lets it run java, xvfb-run or env first.
        before = convert_start_line(pre_executable, conversion)
        template = f"{before} {executable} {template}".strip()
        block["exe"] = "gse_start.sh"
        block["wrapper"] = True
    block["template"] = template
    block["conversion"] = conversion
    if use_map_list:
        block["maps_location"] = f"{game_folder}/maps/"

    config_path = paths.resolve(cfg_value(cfg, "servercfgfullpath"))
    if config_path and "%" not in relative_to_game(config_path):
        block["config_files"] = [("Server config", relative_to_game(config_path))]
    return block


def windows_block(appid, steam_info, linux):
    """A <windows> block from Steam's Windows launch entry, when it names a real .exe."""
    if linux.get("wrapper"):
        return None
    info = steam_info.get(appid, {})
    if "windows" not in info.get("oses", []):
        return None
    for launch in info.get("launch", []):
        if launch.get("os") not in ("windows", ""):
            continue
        path = launch.get("exe", "").replace("\\", "/").strip("/")
        name = path.split("/")[-1]
        if not name.lower().endswith(".exe") or "launcher" in name.lower():
            continue
        folder = "/".join(path.split("/")[:-1])
        return {"exe": name, "exe_location": folder, "template": linux["template"],
                "conversion": linux["conversion"], "maps_location": linux.get("maps_location", "")}
    return None


def install_script(short_name, steam_game, linux):
    """The post_install bash for the Linux block."""
    parts = []
    log_folders = sorted(set(re.findall(r"%HOME_PATH%/([^\s\"']+)/[^/\s\"']+\.log", linux["template"])))
    if log_folders:
        parts.append("# Folders the server writes its log into.\nmkdir -p " + " ".join(log_folders))
    if short_name in install_steps.DOWNLOADS:
        parts.append(install_steps.DOWNLOADS[short_name])
    if steam_game:
        parts.append(steam_client_script(short_name))
    if short_name in install_steps.FIXES:
        parts.append(install_steps.FIXES[short_name])
    if linux.get("wrapper"):
        folder = linux["exe_location"] or "."
        parts.append(f"# Lets the agent start java, xvfb-run or env first (see the start line).\n"
                     f"printf '#!/bin/sh\\nexec \"$@\"\\n' > \"{folder}/gse_start.sh\"\n"
                     f"chmod +x \"{folder}/gse_start.sh\"")
    return "\n\n".join(part.strip() for part in parts)


def mod_entry(cfg, short_name):
    appid = cfg_value(cfg, "appid")
    if not appid:
        return {"key": "default", "name": "Default"}
    entry = {"key": "default", "name": "Default", "installer_name": appid}
    if appid == "90":
        # HLDS mods: the panel passes the mod key to SteamCMD as the HLDS mod.
        entry["key"] = cfg_value(cfg, "appidmod") or "cstrike"
    if "steamuser" not in cfg:
        entry["installer_login"] = "anonymous"
    if cfg_value(cfg, "branch"):
        entry["betaname"] = cfg_value(cfg, "branch")
    if cfg_value(cfg, "betapassword"):
        entry["betapwd"] = cfg_value(cfg, "betapassword")
    return entry


def game_from_linuxgsm(short_name, key, name, lgsm_dir, steam_info):
    server = short_name + "server"
    cfg = read_cfg(lgsm_dir / "lgsm/config-default/config-lgsm" / server / "_default.cfg")
    appid = cfg_value(cfg, "appid")
    linux = linux_block(short_name, cfg)
    linux["post_install"] = install_script(short_name, bool(appid), linux)
    if short_name in install_steps.ENVIRONMENT:
        linux["environment"] = install_steps.ENVIRONMENT[short_name]
    game = {
        "key": key, "name": name, "steam": bool(appid), "mod": mod_entry(cfg, short_name),
        "linux": linux, "windows": None, "notes": [],
        "source": f"LinuxGSM {server} (lgsm/config-default/config-lgsm/{server}/_default.cfg)",
    }
    if appid and short_name not in install_steps.DOWNLOADS and short_name not in install_steps.WINDOWS_SKIP:
        game["windows"] = windows_block(appid, steam_info, linux)
    if game["windows"]:
        game["notes"].append("The Windows block uses Steam's Windows executable with the same start options; not yet tested on a GSE agent.")
    if short_name in install_steps.NOTES:
        game["notes"].append(install_steps.NOTES[short_name])
    if "%PORT%" not in linux["template"]:
        files = ", ".join(path for _, path in linux.get("config_files", [])) or "its config file"
        game["notes"].append(f"LinuxGSM starts this server without a port option; the port is read from {files}. "
                             "Set it there to the port the panel assigned.")
    players = cfg_value(cfg, "maxplayers")
    game["max_players"] = int(players) if players.isdigit() and int(players) > 1 else None
    querytype = cfg_value(cfg, "querytype")
    if querytype == "protocol-valve" and cfg_value(cfg, "engine") in ("source", "goldsrc") and "QUERYPORT" not in linux["conversion"].ports:
        game["query"] = "halflife" if cfg_value(cfg, "engine") == "goldsrc" else "source"
    return game


class FixedConversion:
    """Lists what a hand-written template (extra_games.py) uses."""

    def __init__(self, template, ports, params):
        found = set(re.findall(r"%([A-Z_]+)%", template))
        cli_ids = ["IP", "PORT", "PLAYERS", "HOSTNAME", "CONTROL_PASSWORD", "HOME_PATH", "MAP"]
        self.cli_params = [name for name in cli_ids if name in found]
        self.ports = dict(ports)
        self.settings = list(params)
        unknown = found - set(cli_ids) - set(self.ports) - {param["id"] for param in self.settings}
        if unknown:
            raise SystemExit(f"Template uses undefined placeholders: {sorted(unknown)}")


def game_from_extra(extra):
    windows = dict(extra["windows"])
    windows.setdefault("exe_location", "")
    windows["conversion"] = FixedConversion(windows["template"], windows.get("ports", {}), windows.get("params", []))
    return {
        "key": extra["key"], "name": extra["name"], "steam": True,
        "mod": {"key": "default", "name": "Default", "installer_name": extra["appid"], "installer_login": "anonymous"},
        "linux": None, "windows": windows, "notes": list(extra["notes"]),
        "max_players": extra.get("max_players"), "source": "Other hosts' game lists (see tools/lgsm_xml/extra_games.py)",
    }


# ---------- writing XML ----------

def escape(text):
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;")


def cdata(text):
    return "<![CDATA[\n" + text + "\n]]>"


def platform_xml(tag, block, indent="    "):
    inner = indent + "  "
    conversion = block["conversion"]
    lines = [f'{indent}<{tag} arch="x64">', f"{inner}<exe>{escape(block['exe'])}</exe>"]
    if block.get("exe_location"):
        lines.append(f"{inner}<exe_location>{escape(block['exe_location'])}</exe_location>")
    if block.get("template"):
        lines.append(f"{inner}<cli_template>{escape(block['template'])}</cli_template>")
    if conversion.cli_params:
        lines.append(f"{inner}<cli_params>")
        for param_id in conversion.cli_params:
            lines.append(f'{inner}  <cli_param id="{param_id}" cli_string=""/>')
        lines.append(f"{inner}</cli_params>")
    if conversion.ports:
        lines.append(f"{inner}<reserve_ports>")
        for port_id, offset in conversion.ports.items():
            kind = "add" if offset >= 0 else "subtract"
            lines.append(f'{inner}  <port type="{kind}" id="{port_id}" cli_string="">{abs(offset)}</port>')
        lines.append(f"{inner}</reserve_ports>")
    if block.get("maps_location"):
        lines.append(f"{inner}<maps_location>{escape(block['maps_location'])}</maps_location>")
    if conversion.settings:
        lines.append(f"{inner}<server_params>")
        for setting in conversion.settings:
            if setting.get("type") == "checkbox_key_value":
                # A tick box adds its key to the end of the start line when ticked.
                lines.append(f'{inner}  <param key="{escape(setting["key"])}" type="checkbox_key_value">')
            else:
                lines.append(f'{inner}  <param id="{setting["id"]}" key="{escape(setting["key"])}" type="text">')
                lines.append(f"{inner}    <default>{escape(setting['default'])}</default>" if setting["default"] else f"{inner}    <default/>")
            lines.append(f"{inner}    <caption>{escape(setting['caption'])}</caption>")
            if setting.get("desc"):
                lines.append(f"{inner}    <desc>{escape(setting['desc'])}</desc>")
            if setting.get("options"):
                lines.append(f"{inner}    <options>{setting['options']}</options>")
            lines.append(f"{inner}  </param>")
        lines.append(f"{inner}</server_params>")
    if block.get("post_install"):
        lines.append(f"{inner}<post_install>{cdata(block['post_install'])}</post_install>")
    if block.get("environment"):
        lines.append(f"{inner}<environment_variables>\n{escape(block['environment'])}\n{inner}</environment_variables>")
    if block.get("config_files"):
        lines.append(f"{inner}<configuration_files>")
        for description, path in block["config_files"]:
            lines.append(f'{inner}  <file description="{escape(description)}">{escape(path)}</file>')
        lines.append(f"{inner}</configuration_files>")
    lines.append(f"{indent}</{tag}>")
    return lines


def game_xml(game):
    lines = ['<?xml version="1.0" encoding="utf-8"?>']
    lines.append(f"<!-- Generated by tools/lgsm_xml/generate.py from {escape(game['source'])}. -->")
    for note in game["notes"]:
        lines.append(f"<!-- Note: {escape(note)} -->")
    lines.append("<game_config>")
    lines.append(f"  <game_key>{escape(game['key'])}</game_key>")
    if game.get("query"):
        lines.append("  <protocol>lgsl</protocol>")
        lines.append(f"  <lgsl_query_name>{game['query']}</lgsl_query_name>")
    if game["steam"]:
        lines.append("  <installer>steamcmd</installer>")
    lines.append(f"  <game_name>{escape(game['name'])}</game_name>")
    lines.append("  <os>")
    if game["windows"]:
        lines.extend(platform_xml("windows", game["windows"]))
    if game["linux"]:
        lines.extend(platform_xml("linux", game["linux"]))
    lines.append("  </os>")
    if game.get("max_players"):
        lines.append(f"  <max_user_amount>{game['max_players']}</max_user_amount>")
    mod = game["mod"]
    lines.append("  <mods>")
    lines.append(f'    <mod key="{escape(mod["key"])}">')
    lines.append(f"      <name>{escape(mod['name'])}</name>")
    for tag in ("installer_name", "installer_login", "betaname", "betapwd"):
        if mod.get(tag):
            lines.append(f"      <{tag}>{escape(mod[tag])}</{tag}>")
    lines.append("    </mod>")
    lines.append("  </mods>")
    lines.append("</game_config>")
    return "\n".join(lines) + "\n"


def main():
    lgsm_dir, steam_file, output_dir = Path(sys.argv[1]), Path(sys.argv[2]), Path(sys.argv[3])
    steam_info = json.loads(steam_file.read_text()) if steam_file.exists() else {}
    games = [game_from_linuxgsm(short, key, name, lgsm_dir, steam_info) for short, (key, name) in NEW.items()]
    games += [game_from_extra(extra) for extra in EXTRAS]
    written, skipped = [], []
    for game in games:
        path = output_dir / f"{game['key']}.xml"
        if path.exists():
            skipped.append(game["key"])
            continue
        path.write_text(game_xml(game))
        written.append(game["key"])
    print(f"wrote {len(written)} new XML files; skipped {len(skipped)} that already exist: {skipped}")


if __name__ == "__main__":
    main()
