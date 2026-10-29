#!/bin/bash

#		--dry-run


sshpass -p REMOVED-CREDENTIAL! \
rsync -avP --delete -e "ssh -p 12322" \
--link-dest /sdb1/backup/gameserver/00-latest-kcwin.D.drive \
cyg_server@kcwin.iaregamer.com:/cygdrive/d/GSP64/home/gameserver/1437 \
/sdb1/backup/gameserver/07-Sunday-kcwin.D.drive/1437

