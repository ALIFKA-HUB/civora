# Model data

[Kembali ke indeks](README.md)

Model ini merupakan rancangan relasional, bukan migration yang sudah dijalankan. ID internal memakai UUID; identifier sekolah tetap teks. Timestamp disimpan dengan timezone. Semua relasi ke riwayat memakai pembatasan penghapusan, bukan cascade yang menghapus catatan.

## Identitas dan akademik

| Entitas | Field inti dan relasi |
| --- | --- |
| school_settings | Singleton: nama sekolah, timezone, logo opsional, pengaturan produk |
| users | id, role, name, password_hash, account_status, must_change_password, timestamps |
| login_identifiers | user_id, role, kind, normalized_value; alias NISN/NIP/kode guru/username |
| students | id, user_id unik, nisn unik, education_status active/transferred/graduated |
| teachers | id, user_id unik, nip nullable unik, teacher_code unik |
| temporary_credentials | user_id, encrypted_secret, expires_at, consumed_at, generated_by, reason |
| academic_years | id, name unik, start_date, end_date |
| semesters | id, academic_year_id, name, start_date, end_date, status draft/active/closed |
| majors | id, code unik, name, active |
| classrooms | id, academic_year_id, major_id, code, name, grade, active |
| enrollments | id, student_id, semester_id, classroom_id, valid_from, valid_until nullable |

Classroom adalah kelas fisik/administratif pada tahun ajaran, misalnya X RPL 1. Penempatan menghubungkan siswa, semester, dan kelas dalam periode tertentu. Jurusan kelas yang sudah memiliki penempatan tidak diubah; kesalahan diperbaiki melalui kelas/penempatan yang benar agar laporan historis tidak bergeser.

Perubahan nama/kode jurusan atau kelas bersifat administratif dan diaudit. Pada catatan poin, label kelas/jurusan saat kejadian juga disimpan untuk tampilan historis. Koreksi NISN tidak mengganti user/student ID.

## Poin dan pemeriksaan

| Entitas | Field inti dan relasi |
| --- | --- |
| point_types | id, code unik, category achievement/violation, active |
| point_type_versions | id, point_type_id, version, name, description, points, created_by, created_at |
| student_selections | id, teacher_id, student_id, enrollment_id, semester_id, source qr/manual, expires_at, consumed_at |
| qr_tokens | id, student_id, session_id, token_hash unik, issued_at, expires_at, consumed_at, revoked_at |
| qr_redemptions | id, qr_token_id unik, teacher_id, request_key, selection_id unik, created_at |
| point_records | id, student_id, teacher_id, enrollment_id, semester_id, selection_id unik, source, occurred_at, class_snapshot, major_snapshot, idempotency_key, request_hash |
| record_revisions | id, point_record_id, revision_number, point_type_version_id, points_snapshot, name_snapshot, category_snapshot, description, attachment_id nullable, status active/cancelled, changed_by, reason, created_at |
| review_requests | id, point_record_id, requester_user_id, kind student_objection/teacher_correction, reason, proposed_change, status pending/approved/rejected, reviewed_revision, decided_by, decision_reason, decided_at |
| attachments | id, owner_user_id, private_path, mime_type, size, checksum, created_at |

Revisi pertama berasal dari guru dan menyimpan nilai/nama/kategori versi jenis poin saat pencatatan. Revisi berikutnya dibuat admin. Nilai efektif berasal dari revision_number tertinggi. Tidak ada nilai poin yang dihitung dari versi katalog terbaru untuk semua riwayat.

Kepemilikan siswa, guru pencatat, penempatan, dan semester pada point_records tidak diubah oleh revisi. Bukti lama tetap direferensikan oleh revisi lama dan tidak dapat dihapus oleh guru setelah catatan berlaku.

## Ambang, tindakan, notifikasi, dan operasi

| Entitas | Field inti dan relasi |
| --- | --- |
| threshold_policies | id, semester_id, version, created_by, reason, created_at |
| threshold_levels | id, policy_id, boundary_points, label, recommended_action |
| threshold_alerts | id, student_id, semester_id, threshold_level_id, reached_at, currently_reached, last_evaluated_at |
| follow_ups | id, threshold_alert_id, performed_by, action, scheduled_or_performed_at, notes, status planned/completed/cancelled |
| notifications | id, user_id, event_key, type, resource_id, public_message, read_at, created_at |
| device_tokens | id, user_id, app student/teacher, token unik, platform, active, updated_at |
| outbox_events | id, event_key unik, type, resource_id, payload minimal, processed_at, attempts, next_attempt_at |
| import_batches | id, admin_id, entity_type, file_path privat, semester_id nullable, status, row_count, error_summary, confirmed_at |
| report_exports | id, admin_id, filters, status, file_path privat, generated_at, expires_at |
| audit_logs | id, actor_user_id nullable, action, resource_type, resource_id, safe_before, safe_after, reason, request_id, created_at |
| auth/session/queue tables | Tabel bawaan Laravel untuk Sanctum, session, cache, jobs, dan failed_jobs |

Outbox adalah daftar event yang ditulis dalam transaksi bisnis yang sama, lalu dikirim worker. Ia mencegah hilangnya notifikasi jika proses mati setelah catatan berhasil disimpan. Payload tidak memuat password, QR mentah, atau foto.

## Batasan database

- UNIQUE students.nisn, teachers.teacher_code, teachers.nip jika tidak null.
- UNIQUE login_identifiers(role, normalized_value), sehingga alias NIP/kode guru tidak ambigu.
- UNIQUE classrooms(academic_year_id, code), semesters(academic_year_id, name), point_type_versions(point_type_id, version).
- Partial UNIQUE untuk satu semester berstatus active. Pemeriksaan rentang tanggal dilengkapi validasi transaksi pembukaan semester.
- Rentang enrollments satu siswa/semester tidak tumpang tindih; gunakan exclusion constraint PostgreSQL dengan rentang waktu dan ekstensi btree_gist, selain penguncian transaksi pada mutasi penempatan.
- Rentang enrollments harus berada dalam rentang semester dan sesuai tahun ajaran kelas.
- UNIQUE point_records(teacher_id, idempotency_key) dan UNIQUE point_records.selection_id.
- UNIQUE qr_redemptions.qr_token_id; request_key unik per guru untuk retry scan yang sama.
- UNIQUE record_revisions(point_record_id, revision_number).
- Partial UNIQUE review_requests(point_record_id) WHERE status = pending.
- UNIQUE threshold_policies(semester_id, version), threshold_levels(policy_id, boundary_points), threshold_alerts(student_id, semester_id, threshold_level_id).
- UNIQUE notifications(user_id, event_key) dan outbox_events.event_key untuk mencegah duplikasi notifikasi.
- CHECK points dan boundary_points berupa integer positif, valid_from < valid_until jika akhir diisi, serta enum status yang sah.
- Akun, penempatan, jenis poin, dan catatan yang direferensikan riwayat tidak boleh dihapus permanen melalui UI.

Aturan yang melibatkan beberapa tabel, seperti student_id/enrollment_id/semester_id yang cocok, harus diverifikasi server di dalam transaksi. Constraint bukan pengganti pemeriksaan role.

## Indeks utama

- point_records(student_id, semester_id, occurred_at, id) untuk riwayat dan total.
- point_records(teacher_id, occurred_at, id) untuk riwayat guru.
- point_records(enrollment_id, occurred_at, id) untuk rekap kelas historis.
- record_revisions(point_record_id, revision_number DESC) untuk membaca revisi efektif.
- review_requests(status, created_at, id), notifications(user_id, read_at, created_at, id).
- qr_tokens(token_hash), qr_tokens(expires_at), student_selections(teacher_id, expires_at).
- enrollments(student_id, semester_id, valid_from), enrollments(classroom_id, semester_id).
- Pencarian nama memakai query terparameterisasi; indeks tambahan seperti trigram hanya ditambahkan bila hasil pengukuran membutuhkannya.

## Transaksi penting

1. Buat/import akun: user, profil, login aliases, password sementara, penempatan, dan audit commit bersama.
2. Scan: kunci token QR, validasi, konsumsi, buat selection dan redemption bersama. Retry milik guru yang sama mengembalikan selection yang sama.
3. Simpan poin: cek idempotensi terlebih dahulu, kunci selection, siswa dan semester, validasi katalog/penempatan, buat record dan revisi pertama, konsumsi selection, evaluasi ambang, dan tulis outbox bersama.
4. Koreksi: kunci siswa dan record, cek versi yang diperiksa, append revisi, putuskan review, evaluasi ambang, dan tulis outbox bersama.
5. Ganti semester/perpindahan/penonaktifan: gunakan urutan lock yang sama dengan pencatatan agar tidak ada catatan masuk ke penempatan yang sudah berakhir.

Mutasi poin untuk siswa/semester yang sama diserialisasi melalui penguncian siswa sehingga dua catatan paralel tidak melewatkan ambang gabungan. Urutan lock global adalah semester, siswa, lalu token/selection/record dan resource turunannya. Lookup untuk memperoleh ID dilakukan sebelum lock, lalu kondisi dibaca ulang setelah lock. Mutasi yang tidak memakai semester, misalnya reset akun, mulai dari siswa dan tetap mengikuti urutan resource turunannya. Konflik/deadlock ditangani dengan retry terbatas.

## Penyimpanan dan retensi teknis

- QR mentah tidak disimpan; hash dan metadata saja. Redemption lalu token kedaluwarsa dibersihkan setelah 24 jam. Selection yang kedaluwarsa dan tidak dipakai dibersihkan setelah 24 jam; selection yang direferensikan point record tetap disimpan. Point record tetap menyimpan sumber metode.
- Password sementara terenkripsi dihapus saat aktivasi atau kedaluwarsa; hash login terkait tidak memberi akses jika expired.
- Berkas import dan hasil laporan privat kedaluwarsa setelah 7 hari; lampiran staged yang tidak dipakai dibersihkan setelah 24 jam.
- Riwayat siswa, revisi, audit, dan bukti yang sudah terikat catatan dipertahankan, sesuai keputusan brainstorming. Sebelum produksi, sekolah menetapkan kebijakan retensi yang lebih rinci; ini tidak boleh ditentukan dengan menghapus arsip diam-diam.

Rujukan teknis: [PostgreSQL constraints](https://www.postgresql.org/docs/current/ddl-constraints.html).
