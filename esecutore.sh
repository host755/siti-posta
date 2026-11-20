#!/bin/bash
# Esecutore del progetto SitiDemo: lanciato da Plesk ogni minuto come utente del dominio cloud755.com.
# Per ogni file nuovo posta/ID.json lancia invia.php ID (un ID gia' eseguito non si ripete mai) e pubblica l'esito
# nel ramo "esiti" del repository (file esiti/ID.txt). Gli esiti non contengono dati dei clienti.
STATO=/var/www/vhosts/cloud755.com/posta-stato
RADICE=/var/www/vhosts/cloud755.com/siti-posta
export GIT_SSH_COMMAND="ssh -i $STATO/chiave_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=$STATO/known_hosts"
mkdir -p "$STATO/esiti"
exec 9>"$STATO/blocco"
flock -n 9 || exit 0

cd "$RADICE" || exit 1
git pull -q --ff-only || exit 1
[ -d posta ] || exit 0

FATTI="$STATO/fatti.txt"
touch "$FATTI"
nuovi=0
for f in posta/*.json; do
  [ -e "$f" ] || continue
  id="$(basename "$f" .json)"
  [[ "$id" =~ ^[A-Za-z0-9_-]{1,60}$ ]] || continue
  grep -qxF "$id" "$FATTI" && continue
  echo "$id" >> "$FATTI"   # segnato prima di lanciare: non si ripete mai
  nuovi=1
  {
    echo "ID: $id"
    echo "Inizio: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "-----"
    timeout 120 /opt/plesk/php/8.3/bin/php -d display_errors=1 invia.php "$id" 2>&1
    echo "-----"
    echo "Uscita: $?"
    echo "Fine: $(date '+%Y-%m-%d %H:%M:%S')"
  } > "$STATO/esiti/$id.txt"
done
[ "$nuovi" = 1 ] || exit 0

# Pubblicazione degli esiti nel ramo "esiti" (copia separata: non tocca main).
E="$STATO/copia-esiti"
if [ ! -d "$E/.git" ]; then
  git clone -q "$(git -C "$RADICE" remote get-url origin)" "$E" || exit 1
fi
cd "$E" || exit 1
git fetch -q origin
if git rev-parse -q --verify origin/esiti >/dev/null; then
  git checkout -q -B esiti origin/esiti
else
  git checkout -q --orphan esiti
  git rm -rfq . >/dev/null 2>&1
fi
mkdir -p esiti
cp "$STATO"/esiti/*.txt esiti/
git add esiti
git -c user.name="esecutore" -c user.email="esecutore@marcoforconi.com" commit -q -m "esiti $(date '+%Y-%m-%d %H:%M')" || exit 0
git push -q origin esiti
