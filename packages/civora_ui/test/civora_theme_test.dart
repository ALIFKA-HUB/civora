import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

double _luminance(Color color) {
  double linearize(double channel) => channel <= 0.04045
      ? channel / 12.92
      : ((channel + 0.055) / 1.055) * ((channel + 0.055) / 1.055);

  return 0.2126 * linearize(color.r) +
      0.7152 * linearize(color.g) +
      0.0722 * linearize(color.b);
}

double _contrastRatio(Color foreground, Color background) {
  final foregroundLuminance = _luminance(foreground);
  final backgroundLuminance = _luminance(background);
  final lighter = foregroundLuminance > backgroundLuminance
      ? foregroundLuminance
      : backgroundLuminance;
  final darker = foregroundLuminance > backgroundLuminance
      ? backgroundLuminance
      : foregroundLuminance;
  return (lighter + 0.05) / (darker + 0.05);
}

void main() {
  test('light and dark themes expose the approved Civora tokens', () {
    expect(CivoraTheme.light.scaffoldBackgroundColor, const Color(0xFFF5F5F6));
    expect(CivoraTheme.light.colorScheme.surface, Colors.white);
    expect(
      CivoraTheme.light.colorScheme.surfaceContainerHighest,
      const Color(0xFFE3E5E8),
    );
    expect(CivoraTheme.light.colorScheme.primary, const Color(0xFF172225));
    expect(CivoraTheme.light.colorScheme.onPrimary, Colors.white);
    expect(CivoraTheme.light.textTheme.headlineSmall?.fontFamily, 'NotoSans');
    expect(CivoraTheme.light.textTheme.bodyMedium?.fontFamily, 'NotoSans');
    expect(CivoraTheme.light.colorScheme.onSurface, const Color(0xFF151719));
    expect(CivoraTheme.light.colorScheme.secondary, const Color(0xFF0B625A));

    expect(CivoraTheme.dark.scaffoldBackgroundColor, const Color(0xFF141719));
    expect(CivoraTheme.dark.colorScheme.surface, const Color(0xFF24292D));
    expect(
      CivoraTheme.dark.colorScheme.surfaceContainerHighest,
      const Color(0xFF30363B),
    );
    expect(CivoraTheme.dark.colorScheme.primary, const Color(0xFFE3E5E8));
    expect(CivoraTheme.dark.colorScheme.onPrimary, const Color(0xFF172225));
    expect(
      CivoraTheme.dark.filledButtonTheme.style?.backgroundColor?.resolve({}),
      const Color(0xFFE3E5E8),
    );
    expect(
      CivoraTheme.dark.filledButtonTheme.style?.foregroundColor?.resolve({}),
      const Color(0xFF172225),
    );
    expect(CivoraTheme.dark.colorScheme.onSurface, const Color(0xFFF3F5F6));
    expect(CivoraTheme.dark.colorScheme.secondary, const Color(0xFF63D1C1));
    expect(
      CivoraTheme.light.filledButtonTheme.style?.minimumSize?.resolve({}),
      const Size(48, 48),
    );
  });

  testWidgets('capsule navigation keeps theme colors and reduced motion', (
    tester,
  ) async {
    var selectedIndex = 0;
    await tester.pumpWidget(
      MaterialApp(
        theme: CivoraTheme.light,
        home: Scaffold(
          bottomNavigationBar: CivoraCapsuleNavigationBar(
            selectedIndex: selectedIndex,
            onDestinationSelected: (index) => selectedIndex = index,
            destinations: const [
              NavigationDestination(
                icon: Icon(Icons.home_outlined),
                label: 'Beranda',
              ),
              NavigationDestination(
                icon: Icon(Icons.history),
                label: 'Riwayat',
              ),
            ],
          ),
        ),
      ),
    );

    expect(find.text('Beranda'), findsOneWidget);
    expect(find.text('Riwayat'), findsOneWidget);
    final lightPill = find.byKey(const Key('capsule-nav-selected-pill'));
    final lightPillRect = tester.getRect(lightPill);
    expect(lightPillRect.height, greaterThanOrEqualTo(48));
    expect(
      lightPillRect.contains(tester.getRect(find.text('Beranda')).center),
      isTrue,
    );
    expect(
      tester.widget<AnimatedContainer>(lightPill).duration,
      const Duration(milliseconds: 180),
    );
    expect(
      tester.widget<Text>(find.text('Beranda')).style?.color,
      Colors.white,
    );
    expect(
      tester.widget<Text>(find.text('Riwayat')).style?.color,
      const Color(0xFF45484D),
    );
    await tester.tap(find.text('Riwayat'));
    expect(selectedIndex, 1);

    await tester.pumpWidget(
      MaterialApp(
        theme: CivoraTheme.dark,
        home: MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: Scaffold(
            bottomNavigationBar: CivoraCapsuleNavigationBar(
              selectedIndex: 0,
              onDestinationSelected: (_) {},
              destinations: const [
                NavigationDestination(
                  icon: Icon(Icons.home_outlined),
                  label: 'Beranda',
                ),
                NavigationDestination(
                  icon: Icon(Icons.history),
                  label: 'Riwayat',
                ),
              ],
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    final darkPill = find.byKey(const Key('capsule-nav-selected-pill'));
    final darkPillRect = tester.getRect(darkPill);
    expect(
      darkPillRect.contains(tester.getRect(find.text('Beranda')).center),
      isTrue,
    );
    expect(tester.widget<AnimatedContainer>(darkPill).duration, Duration.zero);
    expect(
      (tester.widget<AnimatedContainer>(darkPill).decoration as BoxDecoration)
          .color,
      const Color(0xFFE3E5E8),
    );
    expect(
      tester.widget<Text>(find.text('Beranda')).style?.color,
      const Color(0xFF172225),
    );
    expect(
      tester.widget<Text>(find.text('Riwayat')).style?.color,
      const Color(0xFFBAC2C8),
    );
  });

  testWidgets('selected capsule keeps its label at enlarged text size', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(360, 640);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      MaterialApp(
        theme: CivoraTheme.dark,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(
            context,
          ).copyWith(textScaler: const TextScaler.linear(2)),
          child: child!,
        ),
        home: Scaffold(
          bottomNavigationBar: CivoraCapsuleNavigationBar(
            selectedIndex: 0,
            onDestinationSelected: (_) {},
            destinations: const [
              NavigationDestination(
                icon: Icon(Icons.home_outlined),
                selectedIcon: Icon(Icons.home),
                label: 'Beranda',
              ),
              NavigationDestination(
                icon: Icon(Icons.people_outline),
                label: 'Siswa',
              ),
              NavigationDestination(
                icon: Icon(Icons.history),
                label: 'Riwayat',
              ),
              NavigationDestination(
                icon: Icon(Icons.person_outline),
                label: 'Profil',
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final selectedPill = find.byKey(const Key('capsule-nav-selected-pill'));
    expect(
      tester
          .getRect(selectedPill)
          .contains(tester.getRect(find.text('Beranda')).center),
      isTrue,
    );
    expect(tester.getSize(find.text('Beranda')).height, greaterThan(16));
    expect(tester.takeException(), isNull);
  });

  testWidgets('system preference follows platform brightness changes', (
    tester,
  ) async {
    final preference = ThemePreference.system;
    tester.binding.platformDispatcher.platformBrightnessTestValue =
        Brightness.light;
    await tester.pumpWidget(
      MaterialApp(
        theme: CivoraTheme.light,
        darkTheme: CivoraTheme.dark,
        themeMode: preference.themeMode,
        home: Builder(
          builder: (context) => ColoredBox(
            key: const Key('surface'),
            color: Theme.of(context).colorScheme.surface,
          ),
        ),
      ),
    );
    expect(
      tester.widget<ColoredBox>(find.byKey(const Key('surface'))).color,
      CivoraTheme.light.colorScheme.surface,
    );

    tester.binding.platformDispatcher.platformBrightnessTestValue =
        Brightness.dark;
    await tester.pumpAndSettle();
    expect(
      tester.widget<ColoredBox>(find.byKey(const Key('surface'))).color,
      CivoraTheme.dark.colorScheme.surface,
    );
    tester.binding.platformDispatcher.clearAllTestValues();
  });

  testWidgets('focused and disabled control colors retain readable contrast', (
    tester,
  ) async {
    for (final theme in [CivoraTheme.light, CivoraTheme.dark]) {
      final focus = FocusNode();
      await tester.pumpWidget(
        MaterialApp(
          theme: theme,
          home: Scaffold(
            body: Column(
              children: [
                TextField(focusNode: focus),
                FilledButton(
                  key: const Key('disabled-button'),
                  onPressed: null,
                  child: const Text('Nonaktif'),
                ),
              ],
            ),
          ),
        ),
      );
      focus.requestFocus();
      await tester.pumpAndSettle();
      final inputContext = tester.element(find.byType(InputDecorator));
      final inputTheme = Theme.of(inputContext);
      final inputDecorator = tester.widget<InputDecorator>(
        find.byType(InputDecorator),
      );
      expect(inputDecorator.isFocused, isTrue);
      final focusedColor =
          (inputDecorator.decoration.focusedBorder! as OutlineInputBorder)
              .borderSide
              .color;
      final buttonMaterial = tester.widget<Material>(
        find
            .descendant(
              of: find.byKey(const Key('disabled-button')),
              matching: find.byType(Material),
            )
            .first,
      );
      final buttonTextColor = DefaultTextStyle.of(
        tester.element(find.text('Nonaktif')),
      ).style.color;
      expect(buttonTextColor, isNotNull);
      expect(buttonMaterial.color, theme.colorScheme.surfaceContainerHighest);
      expect(buttonTextColor, theme.colorScheme.onSurfaceVariant);
      expect(
        _contrastRatio(focusedColor, inputTheme.scaffoldBackgroundColor),
        greaterThanOrEqualTo(3),
      );
      expect(
        _contrastRatio(buttonTextColor!, buttonMaterial.color!),
        greaterThanOrEqualTo(4.5),
      );
      focus.dispose();
    }
  });
}
