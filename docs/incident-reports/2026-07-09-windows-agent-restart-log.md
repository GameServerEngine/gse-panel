# Windows agent restart log captured during rollback

Captured from the Windows GSP agent after reverting the Windows agent runtime to the July 2 stable version.

```text
Thu Jul  9 10:02:02 2026 New log file created
Thu Jul  9 10:02:02 2026 User running agent script is: gameserver
Thu Jul  9 10:02:02 2026 Reading startup flags from /GSP/startups
Thu Jul  9 10:02:02 2026 Found 173.208.136.12-2000
Thu Jul  9 10:02:02 2026 Starting server_exe arma2oaserver.exe from home /home/gameserver/4.
Thu Jul  9 10:02:02 2026 Startup command [ screen -d -m -t "GSP_HOME_000000004" -c /GSP/gsp_screenrc -S GSP_HOME_000000004 cmd /Q /C _serverStart.bat ] will be executed in dir /home/gameserver/4.
Thu Jul  9 10:02:02 2026 Running takeown commands on path of /home/gameserver/4 and C:\ogp64\home\gameserver\4
Thu Jul  9 10:02:03 2026 Running icacls command: icacls "C:\ogp64\home\gameserver\4" /grant gameserver:\(OI\)\(CI\)F /T >/dev/null 2>&1
Thu Jul  9 10:02:03 2026 Running icacls admin group command: icacls "C:\ogp64\home\gameserver\4" /grant administrators:F /T >/dev/null 2>&1
Thu Jul  9 10:02:18 2026 Created startup flag for 173.208.136.12-2000
Thu Jul  9 10:02:18 2026 Found 173.208.136.12-2100
Thu Jul  9 10:02:19 2026 Starting server_exe arma2oaserver.exe from home /home/gameserver/5.
Thu Jul  9 10:02:19 2026 Startup command [ screen -d -m -t "GSP_HOME_000000005" -c /GSP/gsp_screenrc -S GSP_HOME_000000005 cmd /Q /C _serverStart.bat ] will be executed in dir /home/gameserver/5.
Thu Jul  9 10:02:19 2026 Running takeown commands on path of /home/gameserver/5 and C:\ogp64\home\gameserver\5
Thu Jul  9 10:02:20 2026 Running icacls command: icacls "C:\ogp64\home\gameserver\5" /grant gameserver:\(OI\)\(CI\)F /T >/dev/null 2>&1
Thu Jul  9 10:02:20 2026 Running icacls admin group command: icacls "C:\ogp64\home\gameserver\5" /grant administrators:F /T >/dev/null 2>&1
Thu Jul  9 10:02:31 2026 Created startup flag for 173.208.136.12-2100
Thu Jul  9 10:02:31 2026 Found 173.208.136.12-2200
Thu Jul  9 10:02:31 2026 Starting server_exe arma2oaserver.exe from home /home/gameserver/6.
Thu Jul  9 10:02:32 2026 Startup command [ screen -d -m -t "GSP_HOME_000000006" -c /GSP/gsp_screenrc -S GSP_HOME_000000006 cmd /Q /C _serverStart.bat ] will be executed in dir /home/gameserver/6.
Thu Jul  9 10:02:32 2026 Running takeown commands on path of /home/gameserver/6 and C:\ogp64\home\gameserver\6
Thu Jul  9 10:02:33 2026 Running icacls command: icacls "C:\ogp64\home\gameserver\6" /grant gameserver:\(OI\)\(CI\)F /T >/dev/null 2>&1
Thu Jul  9 10:02:33 2026 Running icacls admin group command: icacls "C:\ogp64\home\gameserver\6" /grant administrators:F /T >/dev/null 2>&1
Thu Jul  9 10:02:48 2026 Created startup flag for 173.208.136.12-2200
Thu Jul  9 10:02:48 2026 Found 173.208.136.12-2300
Thu Jul  9 10:02:48 2026 Starting server_exe arma2oaserver.exe from home /home/gameserver/22.
Thu Jul  9 10:02:48 2026 Startup command [ screen -d -m -t "GSP_HOME_000000022" -c /GSP/gsp_screenrc -S GSP_HOME_000000022 cmd /Q /C _serverStart.bat ] will be executed in dir /home/gameserver/22.
Thu Jul  9 10:02:48 2026 Running takeown commands on path of /home/gameserver/22 and C:\ogp64\home\gameserver\22
Thu Jul  9 10:02:49 2026 Running icacls command: icacls "C:\ogp64\home\gameserver\22" /grant gameserver:\(OI\)\(CI\)F /T >/dev/null 2>&1
Thu Jul  9 10:02:49 2026 Running icacls admin group command: icacls "C:\ogp64\home\gameserver\22" /grant administrators:F /T >/dev/null 2>&1
Thu Jul  9 10:03:03 2026 Created startup flag for 173.208.136.12-2300
Thu Jul  9 10:03:03 2026 Found 173.208.136.12-2600
Thu Jul  9 10:03:03 2026 Starting server_exe Dzsalmodserver.exe from home /home/gameserver/10.
Thu Jul  9 10:03:04 2026 Startup command [ screen -d -m -t "GSP_HOME_000000010" -c /GSP/gsp_screenrc -S GSP_HOME_000000010 cmd /Q /C _serverStart.bat ] will be executed in dir /home/gameserver/10.
Thu Jul  9 10:03:04 2026 Running takeown commands on path of /home/gameserver/10 and C:\ogp64\home\gameserver\10
Thu Jul  9 10:03:06 2026 Running icacls command: icacls "C:\ogp64\home\gameserver\10" /grant gameserver:\(OI\)\(CI\)F /T >/dev/null 2>&1
Thu Jul  9 10:03:06 2026 Running icacls admin group command: icacls "C:\ogp64\home\gameserver\10" /grant administrators:F /T >/dev/null 2>&1
Thu Jul  9 10:03:38 2026 Created startup flag for 173.208.136.12-2600
Thu Jul  9 10:03:38 2026 Found status-10
Thu Jul  9 10:03:38 2026 Starting server_exe  from home STARTING.
Thu Jul  9 10:03:39 2026 Can't find server's install path [ STARTING ].
Thu Jul  9 10:03:39 2026 Found status-22
Thu Jul  9 10:03:39 2026 Starting server_exe  from home STARTING.
Thu Jul  9 10:03:39 2026 Can't find server's install path [ STARTING ].
Thu Jul  9 10:03:39 2026 Found status-4
Thu Jul  9 10:03:39 2026 Starting server_exe  from home STARTING.
Thu Jul  9 10:03:39 2026 Can't find server's install path [ STARTING ].
Thu Jul  9 10:03:39 2026 Found status-5
Thu Jul  9 10:03:39 2026 Starting server_exe  from home STARTING.
Thu Jul  9 10:03:39 2026 Can't find server's install path [ STARTING ].
Thu Jul  9 10:03:39 2026 Found status-6
Thu Jul  9 10:03:40 2026 Starting server_exe  from home STARTING.
Thu Jul  9 10:03:40 2026 Can't find server's install path [ STARTING ].
Thu Jul  9 10:03:40 2026 GameServer Panel - Agent started - v1.4 - port 12679 - PID 173010
Thu Jul  9 10:03:41 2026 Error reading tasks file No such file or directory
```

## Immediate observation

The July 2-style agent restarted the game homes from IP/port startup flags and no longer produced the cygwin handoff spam. It still attempted to process `status-*` files as startup flags, which should be cleaned from `/GSP/startups` during recovery.

## Recovery confirmation screenshots

Screenshots captured in the ChatGPT session showed:

- `screen -ls` on the Windows/Cygwin agent reporting five detached `GSP_HOME_*` sessions for homes 4, 5, 6, 22, and 10.
- The Panel Game Monitor loading immediately and showing a green status for home 6 after the Panel/agent rollback alignment.

## Follow-up note

The failure pattern from the bad Windows agent series was that `screen -ls` showed no active server screens while logs showed screen/watchdog launch and exit behavior. The rollback restored the earlier screen-owned server process behavior.
