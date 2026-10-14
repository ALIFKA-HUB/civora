import 'package:civora_teacher/app.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

const _identifier = String.fromEnvironment('CIVORA_QA_TEACHER_IDENTIFIER');
const _password = String.fromEnvironment('CIVORA_QA_TEACHER_PASSWORD');
const _studentName = String.fromEnvironment('CIVORA_QA_STUDENT_NAME');
const _studentNisn = String.fromEnvironment('CIVORA_QA_STUDENT_NISN');

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets(
    'teacher selects a student, records points, and requests correction',
    (tester) async {
      expect(
        _identifier,
        isNotEmpty,
        reason: 'Provide private QA teacher credentials.',
      );
      expect(
        _password,
        isNotEmpty,
        reason: 'Provide private QA teacher credentials.',
      );
      expect(
        _studentName,
        isNotEmpty,
        reason: 'Provide a synthetic QA student name.',
      );
      expect(
        _studentNisn,
        isNotEmpty,
        reason: 'Provide a synthetic QA student NISN.',
      );

      await tester.pumpWidget(const CivoraApp(roleLabel: 'Guru'));
      await _waitFor(tester, find.text('Masuk'));
      await tester.enterText(find.byType(TextFormField).at(0), _identifier);
      await tester.enterText(find.byType(TextFormField).at(1), _password);
      await tester.tap(find.text('Masuk'));
      await _waitFor(tester, find.text('Scan QR'));

      await tester.tap(find.text('Scan QR'));
      await _waitFor(tester, find.text('Cari siswa secara manual'));
      await tester.tap(find.text('Cari siswa secara manual'));
      await _waitFor(tester, find.text('Nama atau NISN'));
      await tester.enterText(find.byType(TextField).first, _studentNisn);
      await _waitFor(tester, find.text(_studentName));
      await tester.tap(find.text(_studentName).first);
      await _waitFor(tester, find.text('Catat poin'));
      await tester.tap(find.text('Catat poin'));

      final pointTypes = find.byType(DropdownButtonFormField<String>);
      await _waitFor(tester, pointTypes);
      await tester.tap(pointTypes.first);
      await tester.pumpAndSettle();
      final pointOption = find.textContaining('poin').last;
      await tester.tap(pointOption);
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byWidgetPredicate(
          (widget) =>
              widget is TextField &&
              widget.decoration?.labelText == 'Keterangan wajib',
        ),
        'Catatan integrasi M06 untuk verifikasi alur guru.',
      );
      await tester.tap(find.text('Lanjut ke ringkasan'));
      await _waitFor(tester, find.text('Simpan poin'));
      await tester.tap(find.text('Simpan poin'));
      await _waitFor(tester, find.text('Poin berhasil dicatat'));
      await tester.tap(find.text('Selesai'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('Riwayat'));
      await _waitFor(tester, find.text(_studentName));
      await tester.tap(find.byType(ListTile).first);
      await _waitFor(tester, find.text('Minta koreksi'));
      await tester.tap(find.text('Minta koreksi'));
      await _waitFor(tester, find.text('Kirim permintaan'));
      await tester.enterText(
        find.byWidgetPredicate(
          (widget) =>
              widget is TextField &&
              widget.decoration?.labelText == 'Alasan (5–1.000 karakter)',
        ),
        'Mohon koreksi keterangan catatan uji.',
      );
      await tester.enterText(
        find.byWidgetPredicate(
          (widget) =>
              widget is TextField &&
              widget.decoration?.labelText == 'Usulan keterangan',
        ),
        'Keterangan hasil verifikasi integrasi M06.',
      );
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
      final submitRequest = find.text('Kirim permintaan');
      await tester.ensureVisible(submitRequest);
      await tester.pumpAndSettle();
      await tester.tap(submitRequest);
      await _waitFor(
        tester,
        find.text('Permintaan koreksi menunggu pemeriksaan.'),
      );
    },
  );
}

Future<void> _waitFor(WidgetTester tester, Finder finder) async {
  for (var attempt = 0; attempt < 80; attempt++) {
    await tester.pump(const Duration(milliseconds: 250));
    if (finder.evaluate().isNotEmpty) return;
  }
  expect(finder, findsWidgets);
}
