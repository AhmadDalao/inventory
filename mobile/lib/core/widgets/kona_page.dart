import 'package:flutter/material.dart';

import '../theme/kona_theme.dart';

class KonaPage extends StatelessWidget {
  const KonaPage({
    super.key,
    required this.eyebrow,
    required this.title,
    required this.children,
    this.description,
    this.trailing,
    this.bottomAction,
  });

  final String eyebrow;
  final String title;
  final String? description;
  final Widget? trailing;
  final List<Widget> children;
  final Widget? bottomAction;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        bottom: bottomAction == null,
        child: LayoutBuilder(
          builder: (context, constraints) {
            final horizontal = constraints.maxWidth >= 768 ? 32.0 : 18.0;
            return CustomScrollView(
              slivers: [
                SliverPadding(
                  padding: EdgeInsets.fromLTRB(
                    horizontal,
                    18,
                    horizontal,
                    bottomAction == null ? 28 : 110,
                  ),
                  sliver: SliverToBoxAdapter(
                    child: Center(
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 920),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        eyebrow.toUpperCase(),
                                        style: const TextStyle(
                                          color: KonaColors.goldDark,
                                          fontWeight: FontWeight.w700,
                                          letterSpacing: 1.1,
                                          fontSize: 12,
                                        ),
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        title,
                                        style: Theme.of(
                                          context,
                                        ).textTheme.headlineMedium,
                                      ),
                                      if (description != null) ...[
                                        const SizedBox(height: 8),
                                        Text(
                                          description!,
                                          style: const TextStyle(
                                            color: KonaColors.muted,
                                          ),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                                if (trailing != null) ...[
                                  const SizedBox(width: 12),
                                  trailing!,
                                ],
                              ],
                            ),
                            const SizedBox(height: 22),
                            ..._withSpacing(children),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
      bottomNavigationBar: bottomAction == null
          ? null
          : SafeArea(
              minimum: const EdgeInsets.fromLTRB(18, 8, 18, 12),
              child: Align(
                alignment: Alignment.center,
                heightFactor: 1,
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 920),
                  child: bottomAction!,
                ),
              ),
            ),
    );
  }

  List<Widget> _withSpacing(List<Widget> source) {
    final result = <Widget>[];
    for (var index = 0; index < source.length; index++) {
      if (index > 0) result.add(const SizedBox(height: 14));
      result.add(source[index]);
    }
    return result;
  }
}

class KonaSectionCard extends StatelessWidget {
  const KonaSectionCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(18),
    this.color,
  });

  final Widget child;
  final EdgeInsets padding;
  final Color? color;

  @override
  Widget build(BuildContext context) => Card(
    color: color,
    child: Padding(padding: padding, child: child),
  );
}

class SectionHeading extends StatelessWidget {
  const SectionHeading({
    super.key,
    required this.title,
    this.eyebrow,
    this.trailing,
  });

  final String title;
  final String? eyebrow;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Row(
    children: [
      Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (eyebrow != null)
              Text(
                eyebrow!.toUpperCase(),
                style: const TextStyle(
                  color: KonaColors.goldDark,
                  fontWeight: FontWeight.w700,
                  fontSize: 11,
                  letterSpacing: 1,
                ),
              ),
            Text(title, style: Theme.of(context).textTheme.titleLarge),
          ],
        ),
      ),
      ?trailing,
    ],
  );
}
