import 'package:civora_core/civora_core.dart';
import 'package:flutter/material.dart';

class NotificationList extends StatelessWidget {
  const NotificationList({
    super.key,
    required this.items,
    required this.onOpen,
    required this.onRefresh,
    this.isLoading = false,
    this.errorMessage,
    this.onRetry,
  });

  final List<NotificationDto> items;
  final ValueChanged<NotificationDto> onOpen;
  final Future<void> Function() onRefresh;
  final bool isLoading;
  final String? errorMessage;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    if (errorMessage != null) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(errorMessage!),
            TextButton(onPressed: onRetry, child: const Text('Coba lagi')),
          ],
        ),
      );
    }
    if (items.isEmpty) {
      if (isLoading) {
        return const Center(
          child: CircularProgressIndicator(semanticsLabel: 'Memuat notifikasi'),
        );
      }
      return RefreshIndicator(
        onRefresh: onRefresh,
        child: ListView(
          children: const [
            SizedBox(height: 180),
            Center(child: Text('Belum ada notifikasi.')),
          ],
        ),
      );
    }
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: ListView.separated(
        itemCount: items.length,
        separatorBuilder: (_, _) => const Divider(height: 1),
        itemBuilder: (context, index) {
          final item = items[index];
          return ListTile(
            leading: Icon(
              item.readAt == null
                  ? Icons.notifications_active_outlined
                  : Icons.notifications_none,
              color: item.readAt == null
                  ? Theme.of(context).colorScheme.primary
                  : null,
            ),
            title: Text(item.message),
            subtitle: Text(
              MaterialLocalizations.of(
                context,
              ).formatMediumDate(item.createdAt.toLocal()),
            ),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => onOpen(item),
          );
        },
      ),
    );
  }
}
