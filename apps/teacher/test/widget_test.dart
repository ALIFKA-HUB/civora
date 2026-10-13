import 'package:civora_teacher/app.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('teacher app starts with its fixed role label', (tester) async {
    await tester.pumpWidget(const CivoraApp(roleLabel: 'Guru'));
    expect(find.text('Civora Guru'), findsOneWidget);
    expect(find.text('Fondasi aplikasi Guru'), findsOneWidget);
  });
}
