#!/usr/bin/env python3
"""One-time fixes to existing game XMLs, checked against LinuxGSM (2026-11-14).

Usage:
  python3 tools/lgsm_xml/update_existing.py LINUXGSM_DIR CONFIG_DIR

Each change is a plain text edit inside one file, so the git diff shows exactly
what changed. Every edit checks that the text it replaces is there; if a file
does not look as expected the script stops and changes nothing in that file.
"""
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from generate import cfg_value, read_cfg  # noqa: E402


class FileEdit:
    """Holds one file's text while it is edited."""

    def __init__(self, path):
        self.path = path
        self.text = path.read_text()
        self.changes = []

    def replace(self, old, new, why, region=None):
        """Replace old with new once, inside the given block (or the whole file)."""
        start, end = self.region_span(region)
        part = self.text[start:end]
        if part.count(old) != 1:
            raise SystemExit(f"{self.path.name}: expected exactly one {old!r} in {region or 'file'}")
        self.text = self.text[:start] + part.replace(old, new) + self.text[end:]
        self.changes.append(why)

    def region_span(self, region):
        if region is None:
            return 0, len(self.text)
        match = re.search(rf"<{region}\b[^>]*>.*?</{region}>", self.text, re.S)
        if not match:
            raise SystemExit(f"{self.path.name}: no <{region}> block")
        return match.start(), match.end()

    def region(self, name):
        start, end = self.region_span(name)
        return self.text[start:end]

    def save(self):
        if self.changes:
            self.path.write_text(self.text)


# ---------- single fixes found while comparing ----------

def single_fixes(config_dir):
    edits = []

    edit = FileEdit(config_dir / "space_engineers.xml")
    edit.replace("<installer_name>950900</installer_name>", "<installer_name>298740</installer_name>",
                 "Steam app id was 950900 (Operation: Harsh Doorstop); Space Engineers Dedicated Server is 298740")
    edits.append(edit)

    edit = FileEdit(config_dir / "cstrike.xml")
    edit.replace("<mod key=\"cstrike\">\n          <name>none</name>\n        </mod>",
                 "<mod key=\"cstrike\">\n          <name>none</name>\n          <installer_name>90</installer_name>\n        </mod>",
                 "Linux mod had no Steam app id, so SteamCMD was asked for an app called 'cstrike'; HLDS is app 90",
                 region="linux")
    edits.append(edit)

    edit = FileEdit(config_dir / "arma3.xml")
    edit.replace("%CONFIG% %CFG% %PROFILES% %NAME%", "%CONFIG% %CFG% -profiles=profile %NAME%",
                 "Linux start line kept %PROFILES% (no such setting); uses -profiles=profile like Windows",
                 region="linux")
    edits.append(edit)

    edit = FileEdit(config_dir / "lifeisfeudal.xml")
    edit.replace("<cli_template>-worldID %PORT%</cli_template>", "<cli_template>-worldID 1</cli_template>",
                 "Windows start line passed %PORT% (never filled in) as the world id; the world id is 1")
    edits.append(edit)

    edit = FileEdit(config_dir / "rust.xml")
    windows_params = edit.region("windows")
    closing = "      </server_params>\n      <pre_start>"
    if closing not in windows_params:
        raise SystemExit("rust.xml: Windows server_params end not found")
    secure = ('        <param key="+server.secure" type="select" id="SECURE">\n'
              '          <option value="true">Enabled</option>\n'
              '          <option value="false">Disabled</option>\n'
              '          <options>s</options>\n'
              '          <desc>Enables or disables Valve Anti-Cheat protection on the server.</desc>\n'
              '        </param>\n')
    edit.replace(closing, secure + closing,
                 "Windows start line used %SECURE% but only the Linux block defined it", region="windows")
    edits.append(edit)

    edit = FileEdit(config_dir / "tf2.xml")
    edit.replace("<exe>srcds_run</exe>", "<exe>srcds_run_64</exe>",
                 "Linux runs the 64-bit server like LinuxGSM (srcds_run_64)", region="linux")
    edits.append(edit)

    edit = FileEdit(config_dir / "natural_selection_2.xml")
    edit.replace("<exe>server_linux32</exe>", "<exe>server_linux</exe>\n      <exe_location>x64</exe_location>",
                 "Linux server is x64/server_linux (LinuxGSM); the 32-bit build is gone", region="linux")
    edits.append(edit)

    edit = FileEdit(config_dir / "harsh.xml")
    edit.replace("<exe>StartServer</exe>",
                 "<exe>HarshDoorstopServer-Linux-Shipping</exe>\n      <exe_location>HarshDoorstop/Binaries/Linux</exe_location>",
                 "Linux executable is HarshDoorstop/Binaries/Linux/HarshDoorstopServer-Linux-Shipping (LinuxGSM)",
                 region="linux")
    edits.append(edit)
    return edits


# ---------- Source and GoldSrc port options ----------

# Our game_key -> LinuxGSM short name, for games on the Source or GoldSrc engine.
SOURCE_GAMES = {
    "brainbread2": "bb2", "cstrike": "cs", "czero": "cscz", "csgo": "csgo", "csgo128": "csgo",
    "counterstrike_source": "css", "dmc": "dmc", "dod": "dod", "dods": "dods", "doi": "doi",
    "dystopia": "dys", "fistful_of_frags": "fof", "garrysmod": "gmod", "hl2dm": "hl2dm",
    "hldm": "hldm", "insurgency": "ins", "left4dead": "l4d", "left_4_dead": "l4d",
    "left4dead2": "l4d2", "left_4_dead2": "l4d2", "nuclear_dawn": "nd", "nmrih": "nmrih",
    "gearbox": "opfor", "pvkii": "pvkii", "ricochet": "ricochet", "tf2": "tf2", "tfc": "tfc",
    "zps": "zps",
}


def port_line(port_id, offset, cli_string):
    kind = "add" if offset >= 0 else "subtract"
    return f'        <port type="{kind}" id="{port_id}" cli_string="{cli_string}" options="s">{abs(offset)}</port>\n'


def source_ports(key, short_name, lgsm_dir, config_dir):
    """Add LinuxGSM's port options to one Source/GoldSrc Linux block."""
    cfg = read_cfg(lgsm_dir / "lgsm/config-default/config-lgsm" / f"{short_name}server" / "_default.cfg")
    edit = FileEdit(config_dir / f"{key}.xml")
    linux = edit.region("linux")
    template_match = re.search(r"<cli_template>(.*?)</cli_template>", linux, re.S)
    root_template = root_only(edit.text, "cli_template")
    if not template_match and root_template is None:
        raise SystemExit(f"{key}.xml: no cli_template")
    template = template_match.group(1) if template_match else root_template
    added, ports = [], []
    port = int(cfg_value(cfg, "port"))
    if "strictportbind" not in template:
        added.append("-strictportbind")
    exe = re.search(r"<exe>(.*?)</exe>", linux).group(1)
    if exe.startswith(("srcds_run", "hlds_run")) and "norestart" not in template:
        added.append("-norestart")
    if "clientport" not in template and cfg_value(cfg, "clientport").isdigit():
        added.append("%CLIENTPORT%")
        ports.append(port_line("CLIENTPORT", int(cfg_value(cfg, "clientport")) - port, "+clientport"))
    if "tv_port" not in template and cfg_value(cfg, "sourcetvport").isdigit():
        added.append("%TVPORT%")
        ports.append(port_line("TVPORT", int(cfg_value(cfg, "sourcetvport")) - port, "+tv_port"))
    if not added:
        return edit
    new_template = template.rstrip() + " " + " ".join(added)
    why = "Linux start line gets LinuxGSM's " + " ".join(added)
    if template_match:
        edit.replace(f"<cli_template>{template}</cli_template>", f"<cli_template>{new_template}</cli_template>",
                     why, region="linux")
    else:
        # The Linux block used the shared start line; it gets its own copy so Windows is unchanged.
        add_to_linux(edit, f"      <cli_template>{new_template}</cli_template>\n", why + " (own copy of the shared start line)")
    if ports:
        linux = edit.region("linux")
        if "<reserve_ports>" in linux:
            edit.replace("</reserve_ports>", "".join(ports).rstrip("\n") + "\n      </reserve_ports>",
                         "reserve the client/SourceTV ports", region="linux")
        else:
            # A Linux reserve_ports replaces the shared one, so the shared ports are copied in too.
            shared = root_only(edit.text, "reserve_ports") or ""
            shared_ports = "".join(f"        {line.strip()}\n" for line in shared.splitlines() if line.strip())
            add_to_linux(edit, "      <reserve_ports>\n" + shared_ports + "".join(ports) + "      </reserve_ports>\n",
                         "reserve the client/SourceTV ports")
    return edit


def root_only(text, tag):
    """Content of a root-level tag (outside <os>), or None."""
    without_os = re.sub(r"<os>.*?</os>", "", text, flags=re.S)
    match = re.search(rf"<{tag}>(.*?)</{tag}>", without_os, re.S)
    return match.group(1) if match else None


def add_to_linux(edit, xml_text, why):
    """Insert a new child right after the Linux block's <exe> line."""
    linux = edit.region("linux")
    exe_line = re.search(r"<exe>.*?</exe>\n", linux).group(0)
    edit.replace(exe_line, exe_line + xml_text, why, region="linux")


def main():
    lgsm_dir, config_dir = Path(sys.argv[1]), Path(sys.argv[2])
    edits = single_fixes(config_dir)
    for edit in edits:
        edit.save()
    for key, short_name in SOURCE_GAMES.items():
        edit = source_ports(key, short_name, lgsm_dir, config_dir)
        edit.save()
        edits.append(edit)
    for edit in edits:
        for why in edit.changes:
            print(f"{edit.path.name}: {why}")


if __name__ == "__main__":
    main()
