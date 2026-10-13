import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('system preference follows platform brightness changes', (tester) async {
    final preference = ThemePreference.system;
    tester.binding.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    await tester.pumpWidget(MaterialApp(
      theme: CivoraTheme.light,
      darkTheme: CivoraTheme.dark,
      themeMode: preference.themeMode,
      home: Builder(builder: (context) => ColoredBox(
        key: const Key('surface'),
        color: Theme.of(context).colorScheme.surface,
      )),
    ));
    expect(tester.widget<ColoredBox>(find.byKey(const Key('surface'))).color,
      CivoraTheme.light.colorScheme.surface);

    tester.binding.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    await tester.pumpAndSettle();
    expect(tester.widget<ColoredBox>(find.byKey(const Key('surface'))).color,
      CivoraTheme.dark.colorScheme.surface);
    tester.binding.platformDispatcher.clearAllTestValues();
  });
}
