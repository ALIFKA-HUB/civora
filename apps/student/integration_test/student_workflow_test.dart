import 'package:civora_student/app.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';

const _nisn = String.fromEnvironment('CIVORA_QA_STUDENT_NISN');
const _password = String.fromEnvironment('CIVORA_QA_STUDENT_PASSWORD');
const _recordName = String.fromEnvironment('CIVORA_QA_STUDENT_RECORD_NAME');

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('student logs in, refreshes QR, and submits an objection', (
    tester,
  ) async {
    expect(
      _nisn,
      isNotEmpty,
      reason: 'Provide private QA student credentials.',
    );
    expect(
      _password,
      isNotEmpty,
      reason: 'Provide private QA student credentials.',
    );
    expect(
      _recordName,
      isNotEmpty,
      reason: 'Provide a synthetic QA record name.',
    );

    await tester.pumpWidget(const CivoraApp(roleLabel: 'Siswa'));
    await _waitFor(tester, find.text('Masuk'));
    await tester.enterText(find.byType(TextFormField).at(0), _nisn);
    await tester.enterText(find.byType(TextFormField).at(1), _password);
    await tester.tap(find.text('Masuk'));
    await _waitFor(tester, find.text('Catatan terbaru'));

    await tester.tap(find.text('Tampilkan QR'));
    await _waitFor(
      tester,
      find.bySemanticsLabel('QR Civora untuk pemilihan siswa'),
    );
    expect(find.textContaining('detik'), findsOneWidget);
    await tester.tap(find.byType(BackButton));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Riwayat'));
    await _waitFor(tester, find.text(_recordName));
    await tester.tap(find.text(_recordName).first);
    await _waitFor(tester, find.text('Ajukan keberatan'));
    await tester.tap(find.text('Ajukan keberatan'));
    await _waitFor(tester, find.text('Kirim keberatan'));
    await tester.enterText(
      find.byType(TextFormField).first,
      'Mohon periksa kembali catatan uji ini.',
    );
    await tester.tap(find.text('Kirim keberatan'));
    await _waitFor(
      tester,
      find.text(
        'Keberatan dikirim dan menunggu pemeriksaan. Poin tetap berlaku sampai admin memutuskan.',
      ),
    );
  });
}

Future<void> _waitFor(WidgetTester tester, Finder finder) async {
  for (var attempt = 0; attempt < 80; attempt++) {
    await tester.pump(const Duration(milliseconds: 250));
    if (finder.evaluate().isNotEmpty) return;
  }
  expect(finder, findsWidgets);
}
