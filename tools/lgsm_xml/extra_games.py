"""Games other hosts sell that LinuxGSM does not cover.

Taken from the game lists of GameServers.com, Survival Servers and GTXGaming.
Each Steam app id was checked against Steam (api.steamcmd.net) on 2026-11-14.
The executable comes from Steam's own launch entry for the server app.
Start options come from each game's documented server options; none of these
have been tested on a GSE agent yet, so every XML says so in a note.

Placeholders in "template":
  %IP% %PORT% %PLAYERS% %HOSTNAME% %CONTROL_PASSWORD% %HOME_PATH% are panel values.
  Any name in "ports" is an extra port, worked out from the game port.
  Any other %NAME% must be listed in "params".
"""

UNTESTED = "Starting point written from the game's documented server options; not yet tested on a GSE agent."
CONFIG_ONLY = "This server reads its ports and name from its config file, not the command line; edit the config file so the ports match the ones the panel assigned."

EXTRAS = [
    {
        "key": "arkascended", "name": "ARK: Survival Ascended", "appid": "2430930",
        "windows": {
            "exe": "ArkAscendedServer.exe", "exe_location": "ShooterGame/Binaries/Win64",
            "template": "TheIsland_WP?listen?SessionName=\"%HOSTNAME%\"?Port=%PORT%?MaxPlayers=%PLAYERS%?ServerAdminPassword=%CONTROL_PASSWORD%?RCONEnabled=True?RCONPort=%RCONPORT% -WinLiveMaxPlayers=%PLAYERS% -log",
            "ports": {"RCONPORT": 2},
        },
        "max_players": 70, "notes": [UNTESTED],
    },
    {
        "key": "enshrouded", "name": "Enshrouded", "appid": "2278520",
        "windows": {
            "exe": "enshrouded_server.exe", "template": "",
            "ports": {"QUERYPORT": 1},
            "config_files": [("Server settings", "enshrouded_server.json")],
        },
        "max_players": 16, "notes": [UNTESTED, CONFIG_ONLY],
    },
    {
        "key": "vrising", "name": "V Rising", "appid": "1829350",
        "windows": {
            "exe": "VRisingServer.exe",
            "template": "-persistentDataPath \"%HOME_PATH%/save-data\" -serverName \"%HOSTNAME%\" -saveName world1 -logFile \"%HOME_PATH%/logs/VRisingServer.log\" -address %IP% -gamePort %PORT% -queryPort %QUERYPORT% -maxConnectedUsers %PLAYERS%",
            "ports": {"QUERYPORT": 1},
        },
        "max_players": 40, "notes": [UNTESTED],
    },
    {
        "key": "sonsoftheforest", "name": "Sons Of The Forest", "appid": "2465200",
        "windows": {
            "exe": "SonsOfTheForestDS.exe",
            "template": "-userdatapath \"%HOME_PATH%/userdata\" -dedicatedserver.IpAddress %IP% -dedicatedserver.GamePort %PORT% -dedicatedserver.QueryPort %QUERYPORT% -dedicatedserver.BlobSyncPort %BLOBSYNCPORT% -dedicatedserver.MaxPlayers %PLAYERS% -dedicatedserver.ServerName \"%HOSTNAME%\"",
            "ports": {"QUERYPORT": 1, "BLOBSYNCPORT": 2},
        },
        "max_players": 8, "notes": [UNTESTED],
    },
    {
        "key": "icarus", "name": "Icarus", "appid": "2089300",
        "windows": {
            "exe": "IcarusServer.exe",
            "template": "-Log -UserDir=\"%HOME_PATH%/saved\" -SteamServerName=\"%HOSTNAME%\" -PORT=%PORT% -QueryPort=%QUERYPORT%",
            "ports": {"QUERYPORT": 1},
        },
        "max_players": 8, "notes": [UNTESTED],
    },
    {
        "key": "abioticfactor", "name": "Abiotic Factor", "appid": "2857200",
        "windows": {
            "exe": "AbioticFactorServer.exe",
            "template": "-log -newconsole -useperfthreads -NoAsyncLoadingThread -MaxServerPlayers=%PLAYERS% -PORT=%PORT% -QueryPort=%QUERYPORT% -SteamServerName=\"%HOSTNAME%\" -AdminPassword=%CONTROL_PASSWORD% %SERVERPASSWORD%",
            "ports": {"QUERYPORT": 1},
            "params": [{"id": "SERVERPASSWORD", "key": "-ServerPassword=", "options": "ns",
                        "default": "", "caption": "Join password", "desc": "Players need this to join. Leave empty for no password."}],
        },
        "max_players": 6, "notes": [UNTESTED],
    },
    {
        "key": "astroneer", "name": "ASTRONEER", "appid": "728470",
        "windows": {
            "exe": "AstroServer.exe", "template": "",
            "config_files": [("Server settings", "Astro/Saved/Config/WindowsServer/AstroServerSettings.ini"),
                             ("Port (Engine.ini [URL] Port=)", "Astro/Saved/Config/WindowsServer/Engine.ini")],
        },
        "max_players": 8, "notes": [UNTESTED, CONFIG_ONLY],
    },
    {
        "key": "stormworks", "name": "Stormworks: Build and Rescue", "appid": "1247090",
        "windows": {
            "exe": "server64.exe", "template": "",
            "config_files": [("Server settings", "server_config.xml")],
        },
        "max_players": 32, "notes": [UNTESTED, CONFIG_ONLY],
    },
    {
        "key": "medievalengineers", "name": "Medieval Engineers", "appid": "367970",
        "windows": {
            "exe": "MedievalEngineersDedicated.exe", "exe_location": "DedicatedServer64",
            "template": "-console -ignorelastsession -path \"%HOME_PATH%/Instance\"",
            "config_files": [("Server settings", "Instance/MedievalEngineers-Dedicated.cfg")],
        },
        "max_players": 32, "notes": [UNTESTED, CONFIG_ONLY],
    },
    {
        "key": "acc", "name": "Assetto Corsa Competizione", "appid": "1430110",
        "windows": {
            "exe": "accServer.exe", "exe_location": "server", "template": "",
            "config_files": [("Ports (tcpPort, udpPort)", "server/cfg/configuration.json"),
                             ("Server settings", "server/cfg/settings.json"),
                             ("Event", "server/cfg/event.json")],
        },
        "max_players": 30, "notes": [UNTESTED, CONFIG_ONLY],
    },
    {
        "key": "groundbranch", "name": "GROUND BRANCH", "appid": "476400",
        "windows": {
            "exe": "GroundBranchServer-Win64-Shipping.exe", "exe_location": "GroundBranch/Binaries/Win64",
            "template": "-log -MultiHome=%IP% -Port=%PORT% -QueryPort=%QUERYPORT%",
            "ports": {"QUERYPORT": 1},
        },
        "max_players": 16, "notes": [UNTESTED],
    },
]
