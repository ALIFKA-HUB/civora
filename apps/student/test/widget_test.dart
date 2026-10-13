import 'package:civora_student/app.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('student app starts with its fixed role label', (tester) async {
    await tester.pumpWidget(const CivoraApp(roleLabel: 'Siswa'));
    expect(find.text('Civora Siswa'), findsOneWidget);
    expect(find.text('Fondasi aplikasi Siswa'), findsOneWidget);
  });
}
