import 'dart:math' show max;

import 'package:flutter/material.dart';

class CivoraCapsuleNavigationBar extends StatelessWidget {
  const CivoraCapsuleNavigationBar({
    super.key,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.destinations,
  }) : assert(selectedIndex >= 0 && selectedIndex < destinations.length);

  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<NavigationDestination> destinations;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final motion = MediaQuery.disableAnimationsOf(context)
        ? Duration.zero
        : const Duration(milliseconds: 180);

    return SafeArea(
      top: false,
      minimum: const EdgeInsets.fromLTRB(16, 8, 16, 8),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final slotWidth = (constraints.maxWidth - 32) / destinations.length;
          final labelWidth = (slotWidth - 8).clamp(1.0, double.infinity);
          final labelStyle = theme.textTheme.labelSmall;
          var labelHeight = 0.0;
          for (final destination in destinations) {
            final painter = TextPainter(
              text: TextSpan(text: destination.label, style: labelStyle),
              textAlign: TextAlign.center,
              textDirection: Directionality.of(context),
              textScaler: MediaQuery.textScalerOf(context),
            )..layout(maxWidth: labelWidth);
            labelHeight = max(labelHeight, painter.height);
          }
          final height = max(72.0, labelHeight + 22 + 2 + 16);

          return SizedBox(
            width: double.infinity,
            height: height,
            child: Material(
              color: scheme.surfaceContainerHigh,
              shape: const StadiumBorder(),
              clipBehavior: Clip.antiAlias,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  for (var index = 0; index < destinations.length; index++)
                    Expanded(
                      child: _Destination(
                        destination: destinations[index],
                        selected: index == selectedIndex,
                        animationDuration: motion,
                        onTap: () => onDestinationSelected(index),
                      ),
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _Destination extends StatelessWidget {
  const _Destination({
    required this.destination,
    required this.selected,
    required this.animationDuration,
    required this.onTap,
  });

  final NavigationDestination destination;
  final bool selected;
  final Duration animationDuration;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final labelColor = selected ? scheme.onPrimary : scheme.onSurfaceVariant;

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
        child: Semantics(
          label: destination.label,
          button: true,
          selected: selected,
          child: InkWell(
            onTap: onTap,
            customBorder: const StadiumBorder(),
            child: AnimatedContainer(
              key: selected ? const Key('capsule-nav-selected-pill') : null,
              width: double.infinity,
              duration: animationDuration,
              curve: Curves.easeOutCubic,
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
              decoration: BoxDecoration(
                color: selected ? scheme.primary : Colors.transparent,
                borderRadius: BorderRadius.circular(32),
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  IconTheme(
                    data: IconThemeData(color: labelColor, size: 22),
                    child: selected
                        ? (destination.selectedIcon ?? destination.icon)
                        : destination.icon,
                  ),
                  const SizedBox(height: 2),
                  Text(
                    destination.label,
                    textAlign: TextAlign.center,
                    softWrap: true,
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: labelColor,
                      fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
