#!/bin/bash
# Auto-deploy ResepFoto: dijalankan cron di server cPanel tiap 2 menit.
# Mengambil branch main dari GitHub (deploy key) lalu menyalin folder app/ ke document root.
# config.php, database (data/*.sqlite) dan uploads/ di server tidak pernah disentuh karena tidak ada di repo.
REPO="$HOME/repositories/resepfoto"
DOCROOT="$HOME/resepfoto.oziera.co.id"
LOG="$HOME/rf-deploy/deploy.log"
export GIT_SSH_COMMAND="ssh -i $HOME/.ssh/rf_github -o IdentitiesOnly=yes -o UserKnownHostsFile=$HOME/.ssh/known_hosts -o StrictHostKeyChecking=yes"
exec 9>"$HOME/rf-deploy/.lock"; flock -n 9 || exit 0
cd "$REPO" || exit 1
git fetch -q origin main 2>>"$LOG" || exit 1
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && exit 0
git reset -q --hard origin/main
cp -a app/. "$DOCROOT/"
echo "$(date '+%F %T') deploy $(git rev-parse --short HEAD) $(git log -1 --pretty=%s)" >> "$LOG"
