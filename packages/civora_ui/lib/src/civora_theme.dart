import 'package:flutter/material.dart';

enum ThemePreference { system, light, dark }

extension ThemePreferenceMode on ThemePreference {
  ThemeMode get themeMode => switch (this) {
    ThemePreference.system => ThemeMode.system,
    ThemePreference.light => ThemeMode.light,
    ThemePreference.dark => ThemeMode.dark,
  };
}

class CivoraTheme {
  static const _lightPrimary = Color(0xFF0F766E);
  static const _darkPrimary = Color(0xFF5EEAD4);

  static ThemeData get light => _theme(
    brightness: Brightness.light,
    primary: _lightPrimary,
    onPrimary: Colors.white,
    background: const Color(0xFFF7FAFA),
    surface: Colors.white,
    onSurface: const Color(0xFF172B2A),
    secondaryText: const Color(0xFF526866),
    outline: const Color(0xFF708884),
  );

  static ThemeData get dark => _theme(
    brightness: Brightness.dark,
    primary: _darkPrimary,
    onPrimary: const Color(0xFF062E29),
    background: const Color(0xFF101919),
    surface: const Color(0xFF182323),
    onSurface: const Color(0xFFEDF5F4),
    secondaryText: const Color(0xFFA7BFBC),
    outline: const Color(0xFF78938E),
  );

  static ThemeData _theme({required Brightness brightness, required Color primary, required Color onPrimary, required Color background, required Color surface, required Color onSurface, required Color secondaryText, required Color outline}) {
    final scheme = ColorScheme(
      brightness: brightness,
      primary: primary,
      onPrimary: onPrimary,
      secondary: primary,
      onSecondary: onPrimary,
      error: brightness == Brightness.light ? const Color(0xFFB42318) : const Color(0xFFFDA29B),
      onError: brightness == Brightness.light ? Colors.white : const Color(0xFF410002),
      surface: surface,
      onSurface: onSurface,
      outline: outline,
      outlineVariant: brightness == Brightness.light ? const Color(0xFFD3E0DE) : const Color(0xFF38514D),
      surfaceContainerHighest: background,
    );
    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      fontFamily: 'NotoSans',
      textTheme: Typography.material2021().black.apply(bodyColor: onSurface, displayColor: onSurface).copyWith(
        bodyMedium: TextStyle(fontSize: 16, color: onSurface),
        bodySmall: TextStyle(fontSize: 14, color: secondaryText),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: outline)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide(color: primary, width: 2)),
      ),
      filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(minimumSize: const Size(48, 48), backgroundColor: primary, foregroundColor: onPrimary)),
      outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), side: BorderSide(color: outline))),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(minimumSize: const Size(48, 48))),
    );
  }
}
