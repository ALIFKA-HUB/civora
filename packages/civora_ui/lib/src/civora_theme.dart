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
  static const _lightPrimary = Color(0xFF172225);
  static const _darkPrimary = Color(0xFFE3E5E8);
  static const _lightTeal = Color(0xFF0B625A);
  static const _darkTeal = Color(0xFF63D1C1);

  static ThemeData get light => _theme(
    brightness: Brightness.light,
    primary: _lightPrimary,
    background: const Color(0xFFF5F5F6),
    surface: Colors.white,
    raisedSurface: Colors.white,
    emphasisSurface: const Color(0xFFE3E5E8),
    onSurface: const Color(0xFF151719),
    secondaryText: const Color(0xFF45484D),
    onPrimary: Colors.white,
    teal: _lightTeal,
    error: const Color(0xFFB42318),
    onError: Colors.white,
    outline: const Color(0xFF74787D),
  );

  static ThemeData get dark => _theme(
    brightness: Brightness.dark,
    primary: _darkPrimary,
    background: const Color(0xFF141719),
    surface: const Color(0xFF24292D),
    raisedSurface: const Color(0xFF2E3439),
    emphasisSurface: const Color(0xFF30363B),
    onSurface: const Color(0xFFF3F5F6),
    secondaryText: const Color(0xFFBAC2C8),
    onPrimary: const Color(0xFF172225),
    teal: _darkTeal,
    error: const Color(0xFFFF989F),
    onError: const Color(0xFF410002),
    outline: const Color(0xFF879198),
  );

  static ThemeData _theme({
    required Brightness brightness,
    required Color primary,
    required Color background,
    required Color surface,
    required Color raisedSurface,
    required Color emphasisSurface,
    required Color onSurface,
    required Color secondaryText,
    required Color onPrimary,
    required Color teal,
    required Color error,
    required Color onError,
    required Color outline,
  }) {
    final scheme = ColorScheme(
      brightness: brightness,
      primary: primary,
      onPrimary: onPrimary,
      secondary: teal,
      onSecondary: brightness == Brightness.light
          ? Colors.white
          : const Color(0xFF141719),
      error: error,
      onError: onError,
      surface: surface,
      onSurface: onSurface,
      onSurfaceVariant: secondaryText,
      outline: outline,
      outlineVariant: brightness == Brightness.light
          ? const Color(0xFFD4D6D9)
          : const Color(0xFF454C51),
      surfaceContainerLow: surface,
      surfaceContainer: raisedSurface,
      surfaceContainerHigh: raisedSurface,
      surfaceContainerHighest: emphasisSurface,
    );
    final textTheme = Typography.material2021().black
        .apply(bodyColor: onSurface, displayColor: onSurface)
        .apply(fontFamily: 'NotoSans');

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      fontFamily: 'NotoSans',
      textTheme: textTheme.copyWith(
        headlineSmall: textTheme.headlineSmall?.copyWith(
          fontSize: 24,
          fontWeight: FontWeight.w700,
        ),
        titleLarge: textTheme.titleLarge?.copyWith(
          fontSize: 18,
          fontWeight: FontWeight.w600,
        ),
        bodyMedium: textTheme.bodyMedium?.copyWith(
          fontSize: 16,
          color: onSurface,
        ),
        bodySmall: textTheme.bodySmall?.copyWith(
          fontSize: 14,
          color: secondaryText,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: outline),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(color: teal, width: 2),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(48, 48),
          backgroundColor: primary,
          foregroundColor: onPrimary,
          disabledBackgroundColor: emphasisSurface,
          disabledForegroundColor: secondaryText,
          shape: const StadiumBorder(),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 48),
          shape: const StadiumBorder(),
          side: BorderSide(color: outline),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(minimumSize: const Size(48, 48)),
      ),
    );
  }
}
