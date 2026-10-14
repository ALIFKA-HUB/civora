# Runbook backup dan restore Civora

Status: prosedur disiapkan; belum ada staging, lokasi offsite, key backup, atau latihan restore. Target awal adalah umur backup paling lama 24 jam (RPO) dan pemulihan dalam 4 jam (RTO). Keduanya belum terbukti. Jangan mencatat keberhasilan sebelum mengisi [formulir hasil drill](#bukti-drill-yang-wajib-dicatat).

## Apa yang harus dicadangkan

1. Dump PostgreSQL lengkap: seluruh tabel Civora dan Laravel, termasuk record, revision, audit, outbox, `jobs`, `failed_jobs`, session, cache, serta metadata/path attachment. Gunakan format custom `pg_dump` agar `pg_restore` dapat memverifikasi dan memulihkan arsip.
2. Seluruh file di `backend/storage/app/private`, termasuk lampiran bukti, import, dan ekspor yang masih dirujuk. Database dan direktori ini harus berasal dari snapshot yang sama. Jangan mengambil dump dan disk saat aplikasi masih mengubah/menghapus file.
3. Custody terpisah (bukan isi backup aplikasi): `APP_KEY`, kredensial DB yang diperlukan untuk pemulihan, identity/private key dekripsi backup, CA database, service account FCM jika dipakai, serta signing key Android sesuai kebijakan. Backup tanpa key pemulihan bukan backup yang dapat dipulihkan. Jangan masukkan secret ini ke arsip aplikasi atau repo.

File bukti dan ekspor pada disk privat disimpan sebagai plaintext. Otorisasi aplikasi melindungi download, tetapi backup harus dienkripsi sebelum keluar dari host dan volume live harus memakai enkripsi-at-rest yang dikelola infrastruktur. `APP_KEY` juga dibutuhkan untuk membuka `temporary_credentials.encrypted_secret`; sesi/cookie lama dapat tidak berlaku jika key berubah. Jangan membuat key baru saat restore.

Retensi rancangan: backup harian terenkripsi di lokasi terpisah, dengan default 30 hari. Tetapkan retensi aktual, lokasi, enkripsi provider, akses, dan delete policy bersama pemilik infrastruktur. Siapkan PostgreSQL client (`pg_dump`, `pg_restore`, `psql`, `createdb`), GNU tar/coreutils, dan alat enkripsi yang disetujui dan diuji (contoh perintah di bawah memakai `age`); private identity age disimpan di secret custody yang terpisah dari recipient public key.

## Membuat snapshot konsisten

Jalankan dari release backend pada host operasi terotorisasi. Contoh shell berikut memakai PostgreSQL client standar dan `age`; ganti path/waktu dengan nilai workspace operasi, jangan meletakkan password dalam argumen. Kredensial PostgreSQL berasal dari `PGSERVICE`/`.pgpass` berizin ketat atau secret store. Gunakan direktori staging yang hanya dapat dibaca service/operator. `BACKUP_DIR` harus ber-volume lokal terproteksi dan bukan direktori publik.

1. Beri tahu pemilik operasi, catat release/migration, pastikan offsite masih punya ruang dan akses. Aktifkan maintenance/isolasi trafik tulis, lalu hentikan proses worker dan scheduler. Pastikan semua job aktif selesai atau tercatat; jangan biarkan cleanup berjalan selama pengambilan snapshot.
2. Siapkan nama berkas unik dan mode privat:

   ```sh
   umask 077
   : "${BACKUP_DIR:?set a private local staging directory}"
   : "${BACKUP_AGE_RECIPIENT:?set the approved age recipient from secret custody}"
   stamp=$(date -u +%Y%m%dT%H%M%SZ)
   mkdir -m 700 "$BACKUP_DIR/$stamp"
   ```

3. Pastikan koneksi menunjuk ke database Civora yang benar (`psql "$DATABASE_URL" -X -Atc 'select current_database(), inet_server_addr(), inet_server_port()'`) dan simpan hasil non-secret ke catatan operasi. Jangan menaruh URL dengan password pada shell history/log. Database yang dipakai di dump harus cocok dengan disk privat yang akan diarsipkan.
4. Buat kedua bagian sebelum melepas maintenance:

   ```sh
   pg_dump --format=custom --no-owner --no-acl --file="$BACKUP_DIR/$stamp/civora.dump" "$DATABASE_URL"
   tar -czf "$BACKUP_DIR/$stamp/private-storage.tar.gz" -C backend/storage/app/private .
   psql "$DATABASE_URL" -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT 'point_records', count(*) FROM point_records UNION ALL SELECT 'record_revisions', count(*) FROM record_revisions UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs UNION ALL SELECT 'attachments', count(*) FROM attachments UNION ALL SELECT 'outbox_events', count(*) FROM outbox_events UNION ALL SELECT 'outbox_processed', count(*) FROM outbox_events WHERE processed_at IS NOT NULL UNION ALL SELECT 'jobs', count(*) FROM jobs UNION ALL SELECT 'failed_jobs', count(*) FROM failed_jobs ORDER BY 1" > "$BACKUP_DIR/$stamp/row-counts.txt"
   test -s "$BACKUP_DIR/$stamp/civora.dump"
   test -s "$BACKUP_DIR/$stamp/private-storage.tar.gz"
   pg_restore --list "$BACKUP_DIR/$stamp/civora.dump" >/dev/null
   tar -tzf "$BACKUP_DIR/$stamp/private-storage.tar.gz" >/dev/null
   age --encrypt --recipient "$BACKUP_AGE_RECIPIENT" --output "$BACKUP_DIR/$stamp/civora.dump.age" "$BACKUP_DIR/$stamp/civora.dump"
   age --encrypt --recipient "$BACKUP_AGE_RECIPIENT" --output "$BACKUP_DIR/$stamp/private-storage.tar.gz.age" "$BACKUP_DIR/$stamp/private-storage.tar.gz"
   (cd "$BACKUP_DIR/$stamp" && sha256sum civora.dump private-storage.tar.gz row-counts.txt > SHA256SUMS.plaintext)
   (cd "$BACKUP_DIR/$stamp" && sha256sum civora.dump.age private-storage.tar.gz.age > SHA256SUMS.ciphertext)
   ```

5. Pastikan kedua file `.age` tidak kosong. Salin file terenkripsi dan kedua manifest checksum ke tujuan offsite terpisah. Verifikasi checksum ciphertext dengan `cd "$BACKUP_DIR/$stamp" && sha256sum --check SHA256SUMS.ciphertext`, serta ukuran, umur objek, retensi, dan akses baca dari jalur pemulihan. Jangan pernah mengunggah dump/tar plaintext.
6. Hapus plaintext staging dengan prosedur sanitasi media yang disetujui operator, bukan sekadar menganggap `rm` menjamin secure erase pada SSD/COW storage. Catat waktu mulai/selesai, ID backup, ukuran, checksum, lokasi, dan hasil upload tanpa mencatat key. Baru setelah kedua bagian tersedia di offsite, lepaskan maintenance dan hidupkan scheduler/worker.
7. Bila salah satu langkah gagal, pertahankan maintenance sampai integritas database/disk live dipastikan; catat alarm dan eskalasikan. Backup parsial tidak boleh diberi status sukses. Repo belum memiliki command backup atau alarm otomatis.

Untuk dump yang lebih besar, sesuaikan storage/streaming setelah diuji; jangan mengubah prosedur konsistensi atau melewati verifikasi dekripsi dan restore.

## Restore terisolasi

Lakukan drill sebelum produksi, setelah perubahan besar skema/storage, dan berkala sesuai pemilik operasi. Gunakan host/instance PostgreSQL terpisah tanpa route/credential ke production, direktori privat kosong, `APP_URL` lokal/terisolasi, egress FCM ditolak, dan worker/scheduler **tidak dijalankan**. Jangan memakai `.env` production sebagai file kerja dan jangan menjalankan restore terhadap nama DB yang tidak memiliki prefix target drill. Backup dapat memuat data pribadi; aksesnya tetap dibatasi walau drill memakai staging sintetis.

1. Pilih satu ID backup dan catat timestamp/sumber. Ambil ciphertext database, ciphertext disk privat, dua manifest checksum, serta decryption identity dari custody yang berwenang. Verifikasi checksum ciphertext sebelum dekripsi; setelah dekripsi, verifikasi pula checksum plaintext di scratch. Sediakan scratch directory mode `0700` di volume terenkripsi dan `umask 077`.
2. Konfigurasi variabel PostgreSQL hanya untuk instance restore khusus (`PGHOST`, `PGPORT`, `PGUSER`); pastikan host/port tersebut bukan production dan jalur jaringan production diblokir. Jalankan query baca-saja berikut pada database maintenance `postgres`, lalu cocokkan IP/port yang dikembalikan dengan catatan endpoint restore yang telah disetujui. **Jangan melanjutkan** bila endpoint tidak cocok atau meragukan:

   ```sh
   psql --dbname=postgres -X -Atc 'select current_database(), inet_server_addr(), inet_server_port()'
   ```

   Tetapkan nama target eksplisit, misalnya `civora_restore_YYYYMMDD`. Tolak nama target kosong atau selain pola tersebut. Jangan menyalin `DATABASE_URL` production ke terminal atau file drill.
3. Dekripsi hanya ke scratch privat dan verifikasi arsip sebelum restore:

   ```sh
   umask 077
   : "${RESTORE_DIR:?set a private isolated scratch directory}"
   : "${RESTORE_DB:?set a dedicated civora_restore_YYYYMMDD database name}"
   : "${BACKUP_AGE_IDENTITY_FILE:?set the authorized private identity file path}"
   (cd "$RESTORE_DIR" && sha256sum --check SHA256SUMS.ciphertext)
   case "$RESTORE_DB" in civora_restore_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]) ;; *) echo 'Unsafe restore database name' >&2; exit 2;; esac
   age --decrypt --identity "$BACKUP_AGE_IDENTITY_FILE" --output "$RESTORE_DIR/civora.dump" "$RESTORE_DIR/civora.dump.age"
   age --decrypt --identity "$BACKUP_AGE_IDENTITY_FILE" --output "$RESTORE_DIR/private-storage.tar.gz" "$RESTORE_DIR/private-storage.tar.gz.age"
   (cd "$RESTORE_DIR" && sha256sum --check SHA256SUMS.plaintext)
   pg_restore --list "$RESTORE_DIR/civora.dump" >/dev/null
   tar -tzf "$RESTORE_DIR/private-storage.tar.gz" >/dev/null
   ```

   Stage ciphertext in `RESTORE_DIR` before these commands. Verify the archive listing contains expected relative private paths (such as `evidence/`, `imports/`, `reports/`) and no absolute path or `..` traversal. The restore directory must be empty except for the selected backup files before decryption.
4. Create a new empty database on the isolated instance. `RESTORE_DB_OWNER` must be a disposable role on that instance; use a separate operator/admin role only to create DB/extension:

   ```sh
   : "${RESTORE_DB_OWNER:?set a disposable restore role}"
   createdb --maintenance-db=postgres --owner="$RESTORE_DB_OWNER" "$RESTORE_DB"
   psql --dbname="$RESTORE_DB" -X -v ON_ERROR_STOP=1 -c 'CREATE EXTENSION IF NOT EXISTS btree_gist'
   pg_restore --exit-on-error --no-owner --no-privileges --role="$RESTORE_DB_OWNER" --dbname="$RESTORE_DB" "$RESTORE_DIR/civora.dump"
   ```

   If archive restore reports a missing extension, do not weaken restore checks; create only the required extension using an authorized role on this isolated instance, then restart/complete the restore into a clean target. Do not use `--clean` or `--create`; an unexpected existing target is a stop condition.
5. Restore the private disk to a fresh empty directory mounted at the isolated release's `backend/storage/app/private`:

   ```sh
   install -d -m 700 backend/storage/app/private
   test -z "$(find backend/storage/app/private -mindepth 1 -print -quit)"
   tar --extract --gzip --file="$RESTORE_DIR/private-storage.tar.gz" --directory=backend/storage/app/private --no-same-owner --no-same-permissions
   ```

   Confirm ownership and least-privilege permissions for the isolated application service. Do not point this path at the live volume.
6. Use the original `APP_KEY` from authorized custody and a restore-only env file: production mode/debug off, isolated `APP_URL`, isolated DB, private restore disk, `QUEUE_CONNECTION=database`, and no FCM service account path/project. Run `php artisan config:clear`, `php artisan migrate:status`, and `php artisan about`; do not run `migrate:fresh`, rollback, scheduler, or worker. If schema status shows migrations pending, stop: first determine whether the backup pairs with the expected release, then apply only forward migrations on this isolated target if the drill plan requires it.
7. Before application-level checks, prove the active DB identity: `psql --dbname="$RESTORE_DB" -X -Atc 'select current_database(), inet_server_addr(), inet_server_port()'`. Confirm the name matches the target, host/port match the isolated instance record, and the web/API service cannot reach production networks. If any value differs, stop and do not start the app.
8. Generate row counts on the restored target and compare with `row-counts.txt` captured in the same backup directory. Repeat the backup query with `--dbname="$RESTORE_DB"` and the same output format:

   ```sh
   psql --dbname="$RESTORE_DB" -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT 'point_records', count(*) FROM point_records UNION ALL SELECT 'record_revisions', count(*) FROM record_revisions UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs UNION ALL SELECT 'attachments', count(*) FROM attachments UNION ALL SELECT 'outbox_events', count(*) FROM outbox_events UNION ALL SELECT 'outbox_processed', count(*) FROM outbox_events WHERE processed_at IS NOT NULL UNION ALL SELECT 'jobs', count(*) FROM jobs UNION ALL SELECT 'failed_jobs', count(*) FROM failed_jobs ORDER BY 1" > "$RESTORE_DIR/row-counts.actual"
   diff -u "$RESTORE_DIR/row-counts.txt" "$RESTORE_DIR/row-counts.actual"
   ```

   Require record, revision, audit, attachment, processed-event, and queue counts to match exactly. Record any explained difference; do not use record totals alone as proof.
9. With an authorized synthetic owner account, fetch a known private attachment through the application and compare its stored checksum/bytes with the pre-backup evidence. As a different authorized test identity, attempt the same URL and confirm access is denied (403/404 without disclosing content). Check an audit row remains present and readable by authorized admin. Record response code and sanitized request ID only.
10. Keep the worker and scheduler stopped through all checks and inspect pending rows. Select one processed event ID from the restored database, record its `processed_at`, and record notification counts for that event key. With FCM config absent and egress blocked, invoke the same job handler directly in the isolated app:

   ```sh
   : "${PROCESSED_EVENT_ID:?set one UUID selected from processed outbox rows}"
   printf '%s' "$PROCESSED_EVENT_ID" | grep -Eq '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$' || exit 2
   psql --dbname="$RESTORE_DB" -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT id, processed_at, attempts FROM outbox_events WHERE id = '$PROCESSED_EVENT_ID'" > "$RESTORE_DIR/outbox.before"
   psql --dbname="$RESTORE_DB" -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT user_id, count(*) FROM notifications WHERE event_key = (SELECT event_key FROM outbox_events WHERE id = '$PROCESSED_EVENT_ID') GROUP BY user_id ORDER BY user_id" > "$RESTORE_DIR/notifications.before"
   php artisan tinker --execute="(new \\App\\Jobs\\DispatchCivoraNotifications('$PROCESSED_EVENT_ID'))->handle(app(\\App\\Services\\Notifications\\OutboxDispatcher::class));"
   psql --dbname="$RESTORE_DB" -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT id, processed_at, attempts FROM outbox_events WHERE id = '$PROCESSED_EVENT_ID'" > "$RESTORE_DIR/outbox.after"
   psql --dbname="$RESTORE_DB" -X -v ON_ERROR_STOP=1 -At -F '|' -c "SELECT user_id, count(*) FROM notifications WHERE event_key = (SELECT event_key FROM outbox_events WHERE id = '$PROCESSED_EVENT_ID') GROUP BY user_id ORDER BY user_id" > "$RESTORE_DIR/notifications.after"
   diff -u "$RESTORE_DIR/outbox.before" "$RESTORE_DIR/outbox.after"
   diff -u "$RESTORE_DIR/notifications.before" "$RESTORE_DIR/notifications.after"
   ```

   Require the timestamp/attempt count and notification counts to remain unchanged. This invokes no queue worker and the chosen processed event must return before FCM; blocked egress is a second safeguard. Do not replay pending events during the restore check. If a later recovery decision chooses to resume pending events, treat that as an explicit notification-impact decision: the outbox is idempotent for in-app notification keys, but FCM side effects are not transactionally coupled to PostgreSQL and an interrupted send can be repeated. This repository has no safe bulk replay/skip command.
11. Stop the isolated app, remove/decommission the disposable database and restored files using the approved data disposal procedure, and erase decrypted scratch using the approved media process. Preserve the encrypted source backup. Record the actual start/end time and calculate RTO; calculate backup age at recovery start for RPO. Do not copy restored state into live service as part of this drill.

## Bukti drill yang wajib dicatat

Isi satu catatan per latihan di `docs/operations/` atau bukti internal tanpa secret/data personal. Jangan menulis nilai credential, real student content, private key, raw environment, atau backup ke Git.

| Item | Hasil yang dicatat |
| --- | --- |
| Backup ID, timestamp UTC, release/migration | ID dan versi, tanpa path berisi secret |
| Umur backup saat pemulihan dimulai | Durasi nyata dibanding target RPO 24 jam |
| Restore mulai/selesai UTC | Durasi nyata dibanding target RTO 4 jam |
| Keutuhan DB + file | Checksum/dekripsi/listing lulus atau gagal |
| Hitungan record/revision/audit/attachment/outbox | Expected dan actual, dengan alasan selisih |
| Uji lampiran | Pemilik sah berhasil; akun silang ditolak |
| Uji worker/outbox | Worker tidak berjalan saat validasi; event processed tetap processed; pending/outbound impact dijelaskan |
| Isolasi dan disposal | Host/DB/disk target, egress tertutup, target kemudian dibuang |
| Hasil/insiden/perbaikan/pemilik | Bukti, gap, tindak lanjut dan tanggal |

Status I-03 tetap pending sampai operator menjalankan drill nyata dengan key sah, offsite yang nyata, hasil seluruh pin di atas, serta review terpisah. Mock, dump lokal, atau keberhasilan syntax tidak membuktikan RPO/RTO atau kesiapan restore.
