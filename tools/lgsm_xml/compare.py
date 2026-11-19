#!/usr/bin/env python3
"""Compare our existing game XMLs with LinuxGSM (the source of truth for Linux).

Usage:
  python3 tools/lgsm_xml/compare.py LINUXGSM_DIR CONFIG_DIR

For every game in game_list.EXISTING it prints what differs in the Linux block:
  appid    Steam app id
  exe      executable name and the folder it runs from
  flags    LinuxGSM start options our start line does not have
Nothing is changed; the output is a work list.
"""
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from game_list import EXISTING  # noqa: E402
from generate import Conversion, cfg_value, read_cfg, relative_to_game, split_tokens  # noqa: E402
import install_steps  # noqa: E402


def text_of(node, tag):
    found = node.find(tag) if node is not None else None
    return (found.text or "").strip() if found is not None and found.text else ""


def child(linux, root, tag):
    """A Linux block child, or the root one when the Linux block has none (the panel's merge)."""
    found = linux.find(tag)
    return found if found is not None else root.find(tag)


def rendered_start_line(linux, root):
    """Our start line with option names filled in, so it can be compared with LinuxGSM."""
    line = text_of(linux, "cli_template") or text_of(root, "cli_template")
    for cli in child(linux, root, "cli_params") if child(linux, root, "cli_params") is not None else []:
        line = line.replace(f"%{cli.get('id')}%", f"{cli.get('cli_string', '')} value")
    for port in child(linux, root, "reserve_ports") if child(linux, root, "reserve_ports") is not None else []:
        line = line.replace(f"%{port.get('id')}%", f"{port.get('cli_string', '')} value")
    params = child(linux, root, "server_params")
    for param in params.iter("param") if params is not None else []:
        key = param.get("key", "")
        if param.get("id"):
            line = line.replace(f"%{param.get('id')}%", f"{key} value")
        else:
            line += f" {key}"
    return line


def our_linux(path):
    """Appids, exe, exe folder and start line of our Linux block (root values as fallback)."""
    root = ET.parse(path).getroot()
    linux = root.find("os/linux")
    if linux is None:
        return None
    appids = {text_of(mod, "installer_name") for mod in (linux.findall("mods/mod") or root.findall("mods/mod"))}
    return {
        "appids": {appid for appid in appids if appid},
        "exe": text_of(linux, "exe").removeprefix("./"),
        "exe_location": (text_of(linux, "exe_location") or text_of(root, "exe_location")).strip("/"),
        "template": rendered_start_line(linux, root),
    }


def linuxgsm_flags(start_line):
    """Option names in a LinuxGSM start line, such as -port or +map or -QueryPort=."""
    flags = []
    for token in split_tokens(start_line):
        match = re.match(r"^([-+][A-Za-z_][\w.\-]*=?)", token)
        if match:
            flags.append(match.group(1))
    return flags


def compare(short_name, key, lgsm_dir, config_dir):
    cfg = read_cfg(lgsm_dir / "lgsm/config-default/config-lgsm" / f"{short_name}server" / "_default.cfg")
    ours = our_linux(config_dir / f"{key}.xml")
    if ours is None:
        return [f"no Linux block (LinuxGSM runs it on Linux: {cfg_value(cfg, 'executable')})"]
    lines = []
    appid = cfg_value(cfg, "appid")
    if appid and appid not in ours["appids"]:
        lines.append(f"appid: ours {sorted(ours['appids']) or 'none'}, LinuxGSM {appid}")
    paths = Conversion(cfg, False)
    exe = install_steps.EXECUTABLE_OVERRIDES.get(short_name, cfg_value(cfg, "executable")).replace("${serverfiles}/", "./")
    exe = exe.removeprefix("./")
    folder = relative_to_game(paths.resolve(cfg_value(cfg, "executabledir")))
    ours_full = "/".join(part for part in (ours["exe_location"], ours["exe"]) if part)
    lgsm_full = "/".join(part for part in (folder, exe) if part)
    if ours_full.removeprefix("./") != lgsm_full and exe:
        lines.append(f"exe: ours {ours_full}, LinuxGSM {lgsm_full}")
    our_text = ours["template"].lower()
    missing = [flag for flag in linuxgsm_flags(cfg_value(cfg, "startparameters"))
               if flag.lower().rstrip("=") not in our_text]
    if missing:
        lines.append("flags LinuxGSM uses that ours does not: " + " ".join(missing))
    return lines


def main():
    lgsm_dir, config_dir = Path(sys.argv[1]), Path(sys.argv[2])
    for short_name, keys in sorted(EXISTING.items()):
        for key in keys:
            for line in compare(short_name, key, lgsm_dir, config_dir):
                print(f"{key} ({short_name}): {line}")


if __name__ == "__main__":
    main()
