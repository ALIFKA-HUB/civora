import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

class TeacherNotificationsScreen extends StatefulWidget {
  const TeacherNotificationsScreen({
    super.key,
    required this.controller,
    required this.onOpenRecord,
  });
  final NotificationController controller;
  final ValueChanged<String> onOpenRecord;

  @override
  State<TeacherNotificationsScreen> createState() =>
      _TeacherNotificationsScreenState();
}

class _TeacherNotificationsScreenState
    extends State<TeacherNotificationsScreen> {
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      await widget.controller.load();
    } catch (_) {}
  }

  Future<void> _open(NotificationDto item) async {
    try {
      await widget.controller.markRead(item);
      await widget.controller.openResource(item);
      if (mounted && item.resourceId != null) {
        widget.onOpenRecord(item.resourceId!);
      }
    } on NotificationLoginRequired {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Masuk untuk membuka detail notifikasi.'),
          ),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Detail notifikasi tidak tersedia.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Notifikasi')),
    body: AnimatedBuilder(
      animation: widget.controller,
      builder: (context, _) => NotificationList(
        items: widget.controller.notifications,
        errorMessage: widget.controller.errorMessage == null
            ? null
            : 'Notifikasi tidak dapat dimuat.',
        onRetry: _load,
        onRefresh: _load,
        onOpen: _open,
      ),
    ),
  );
}
