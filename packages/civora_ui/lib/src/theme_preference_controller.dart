import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'civora_theme.dart';

class ThemePreferenceController extends ChangeNotifier {
  ThemePreferenceController() {
    _restore();
  }

  ThemePreference preference = ThemePreference.system;

  Future<void> _restore() async {
    try {
      final values = await SharedPreferences.getInstance();
      final value = values.getString('civora.theme');
      preference = ThemePreference.values.firstWhere(
        (item) => item.name == value,
        orElse: () => ThemePreference.system,
      );
    } catch (_) {}
    notifyListeners();
  }

  Future<void> set(ThemePreference value) async {
    preference = value;
    notifyListeners();
    try {
      final values = await SharedPreferences.getInstance();
      await values.setString('civora.theme', value.name);
    } catch (_) {}
  }
}
