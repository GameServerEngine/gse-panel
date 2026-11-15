#!/usr/bin/env python3
"""Look up Steam app info for a list of app ids.

Usage:
  python3 tools/lgsm_xml/steam_info.py IDS_FILE OUTPUT_JSON

IDS_FILE has one Steam app id per line. For each id this keeps the app name,
the operating systems its files are built for, and Steam's launch entries
(executable per OS). generate.py uses the Windows launch entry.
Data comes from api.steamcmd.net, which serves the same app info as
SteamCMD's app_info_print.
"""
import json
import sys
import time
import urllib.request


def look_up(appid):
    with urllib.request.urlopen(f"https://api.steamcmd.net/v1/info/{appid}", timeout=20) as response:
        app = json.load(response).get("data", {}).get(appid) or {}
    oses = set()
    for depot in app.get("depots", {}).values():
        if isinstance(depot, dict):
            for name in depot.get("config", {}).get("oslist", "").split(","):
                if name:
                    oses.add(name)
    launch = []
    for entry in app.get("config", {}).get("launch", {}).values():
        launch.append({
            "exe": entry.get("executable", ""),
            "args": entry.get("arguments", ""),
            "os": entry.get("config", {}).get("oslist", ""),
            "type": entry.get("type", ""),
        })
    return {"name": app.get("common", {}).get("name", ""), "oses": sorted(oses), "launch": launch}


def main():
    ids_file, output = sys.argv[1], sys.argv[2]
    results = {}
    for appid in open(ids_file).read().split():
        try:
            results[appid] = look_up(appid)
        except Exception as error:  # one failed lookup should not stop the rest
            results[appid] = {"error": str(error)}
        time.sleep(0.25)  # be polite to the public API
    with open(output, "w") as handle:
        json.dump(results, handle, indent=1)
    failed = [appid for appid, info in results.items() if not info.get("name")]
    print(f"{len(results)} looked up, failed: {failed}")


if __name__ == "__main__":
    main()
