import 'package:civora_core/civora_core.dart';
import 'package:civora_ui/civora_ui.dart';
import 'package:flutter/material.dart';

class StudentNotificationsScreen extends StatefulWidget {
  const StudentNotificationsScreen({
    super.key,
    required this.controller,
    required this.onOpenRecord,
    this.active = true,
  });
  final NotificationController controller;
  final ValueChanged<String> onOpenRecord;
  final bool active;

  @override
  State<StudentNotificationsScreen> createState() =>
      _StudentNotificationsScreenState();
}

class _StudentNotificationsScreenState
    extends State<StudentNotificationsScreen> {
  @override
  void initState() {
    super.initState();
    if (widget.active) _load();
  }

  @override
  void didUpdateWidget(covariant StudentNotificationsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.active && !oldWidget.active) _load();
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
        isLoading: widget.controller.isLoading,
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
