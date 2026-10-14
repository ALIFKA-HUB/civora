import 'package:timezone/data/latest.dart' as timezone_data;
import 'package:timezone/timezone.dart' as timezone;

bool _databaseReady = false;

String? formatSchoolDate(DateTime instant, String? schoolTimezone) {
  if (schoolTimezone == null || schoolTimezone.isEmpty) return null;
  try {
    if (!_databaseReady) {
      timezone_data.initializeTimeZones();
      _databaseReady = true;
    }
    final local = timezone.TZDateTime.from(
      instant.toUtc(),
      timezone.getLocation(schoolTimezone),
    );
    return '${_twoDigits(local.day)} ${_months[local.month - 1]} ${local.year}, '
        '${_twoDigits(local.hour)}:${_twoDigits(local.minute)}';
  } on timezone.LocationNotFoundException {
    return null;
  }
}

String _twoDigits(int value) => value.toString().padLeft(2, '0');

const _months = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'Mei',
  'Jun',
  'Jul',
  'Agu',
  'Sep',
  'Okt',
  'Nov',
  'Des',
];
