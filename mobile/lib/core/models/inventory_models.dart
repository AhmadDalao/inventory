class StorageLocation {
  const StorageLocation({
    required this.id,
    required this.name,
    this.type = 'storage',
    this.isDefault = false,
  });

  final int id;
  final String name;
  final String type;
  final bool isDefault;

  factory StorageLocation.fromJson(Map<String, dynamic> json) =>
      StorageLocation(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? 'Storage',
        type: json['type'] as String? ?? 'storage',
        isDefault: json['is_default'] == true || json['is_default'] == 1,
      );
}

class ItemPackagePreset {
  const ItemPackagePreset({
    required this.id,
    required this.label,
    required this.piecesPerUnit,
    this.isDefault = false,
  });

  final int id;
  final String label;
  final double piecesPerUnit;
  final bool isDefault;

  factory ItemPackagePreset.fromJson(Map<String, dynamic> json) =>
      ItemPackagePreset(
        id: (json['id'] as num? ?? 0).toInt(),
        label: json['label'] as String? ?? 'Package',
        piecesPerUnit: (json['pieces_per_unit'] as num? ?? 1).toDouble(),
        isDefault: json['is_default'] == true || json['is_default'] == 1,
      );
}

class UsageReason {
  const UsageReason({
    required this.code,
    required this.label,
    required this.sortOrder,
    this.active = true,
    this.requiresCustomText = false,
  });

  final String code;
  final String label;
  final bool active;
  final int sortOrder;
  final bool requiresCustomText;

  factory UsageReason.fromJson(Map<String, dynamic> json) => UsageReason(
    code: normalizeCode(json['code'] as String? ?? ''),
    label: json['label'] as String? ?? 'Reason',
    active:
        json['active'] == null || json['active'] == true || json['active'] == 1,
    sortOrder: (json['sort_order'] as num? ?? 999).toInt(),
    requiresCustomText:
        json['requires_custom_text'] == true ||
        json['requires_custom_text'] == 1,
  );

  static String normalizeCode(String value) {
    final normalized = value.trim().toLowerCase().replaceAll('-', '_');
    return switch (normalized) {
      'noshow' => 'no_show',
      'walk_in' => 'walkin',
      _ => normalized,
    };
  }

  static const defaults = <UsageReason>[
    UsageReason(code: 'online', label: 'Online', sortOrder: 1),
    UsageReason(code: 'walkin', label: 'Walk-in', sortOrder: 2),
    UsageReason(code: 'event', label: 'Event', sortOrder: 3),
    UsageReason(code: 'damage', label: 'Damage', sortOrder: 4),
    UsageReason(code: 'sport', label: 'Sport', sortOrder: 5),
    UsageReason(code: 'school', label: 'School', sortOrder: 6),
    UsageReason(code: 'complimentary', label: 'Complimentary', sortOrder: 7),
    UsageReason(code: 'no_show', label: 'No Show', sortOrder: 8),
    UsageReason(
      code: 'other',
      label: 'Other',
      sortOrder: 9,
      requiresCustomText: true,
    ),
  ];
}

class InventoryItem {
  const InventoryItem({
    required this.id,
    required this.name,
    required this.sku,
    required this.unit,
    required this.quantity,
    required this.storageId,
    required this.storageName,
    this.barcode,
    this.imageUrl,
    this.reorderLevel = 0,
    this.packagePresets = const [],
  });

  final int id;
  final String name;
  final String sku;
  final String? barcode;
  final String unit;
  final double quantity;
  final int storageId;
  final String storageName;
  final String? imageUrl;
  final double reorderLevel;
  final List<ItemPackagePreset> packagePresets;

  factory InventoryItem.fromJson(
    Map<String, dynamic> json, {
    Map<String, dynamic>? balance,
  }) => InventoryItem(
    id: (json['id'] as num).toInt(),
    name: json['name'] as String? ?? 'Item',
    sku: json['sku'] as String? ?? '',
    barcode: json['barcode'] as String?,
    unit: json['unit'] as String? ?? 'pcs',
    quantity:
        (balance?['quantity'] as num? ??
                json['quantity'] as num? ??
                json['current_quantity'] as num? ??
                0)
            .toDouble(),
    storageId:
        (balance?['storage_id'] as num? ?? json['storage_id'] as num? ?? 0)
            .toInt(),
    storageName:
        balance?['storage_name'] as String? ??
        json['storage_name'] as String? ??
        '',
    imageUrl: json['image_url'] as String?,
    reorderLevel: (json['reorder_level'] as num? ?? 0).toDouble(),
    packagePresets: ((json['package_presets'] as List?) ?? const [])
        .whereType<Map>()
        .map(
          (entry) =>
              ItemPackagePreset.fromJson(Map<String, dynamic>.from(entry)),
        )
        .where((preset) => preset.piecesPerUnit > 0)
        .toList(),
  );

  static List<InventoryItem> expandJson(
    Map<String, dynamic> json, {
    int? storageId,
  }) {
    final balances = ((json['balances'] as List?) ?? const [])
        .whereType<Map>()
        .map((entry) => Map<String, dynamic>.from(entry))
        .where(
          (entry) =>
              storageId == null ||
              (entry['storage_id'] as num?)?.toInt() == storageId,
        )
        .toList();
    if (balances.isEmpty) {
      return [InventoryItem.fromJson(json)];
    }
    return balances
        .map((balance) => InventoryItem.fromJson(json, balance: balance))
        .toList();
  }

  InventoryItem copyWith({
    double? quantity,
    int? storageId,
    String? storageName,
  }) => InventoryItem(
    id: id,
    name: name,
    sku: sku,
    barcode: barcode,
    unit: unit,
    quantity: quantity ?? this.quantity,
    storageId: storageId ?? this.storageId,
    storageName: storageName ?? this.storageName,
    imageUrl: imageUrl,
    reorderLevel: reorderLevel,
    packagePresets: packagePresets,
  );
}

class MobileRecipient {
  const MobileRecipient({
    required this.id,
    required this.name,
    this.position,
    this.role = 'staff',
  });

  final int id;
  final String name;
  final String? position;
  final String role;

  factory MobileRecipient.fromJson(Map<String, dynamic> json) =>
      MobileRecipient(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String? ?? 'Employee',
        position: json['position'] as String?,
        role: json['role'] as String? ?? 'staff',
      );
}

class MobileTask {
  const MobileTask({
    required this.id,
    required this.reference,
    required this.title,
    required this.status,
    required this.purpose,
    required this.itemCount,
    required this.quantity,
    this.source,
    this.destination,
    this.allowedActions = const {},
    this.requiresAction = false,
  });

  final int id;
  final String reference;
  final String title;
  final String status;
  final String purpose;
  final int itemCount;
  final double quantity;
  final String? source;
  final String? destination;
  final Set<String> allowedActions;
  final bool requiresAction;

  bool can(String action) => allowedActions.contains(action);

  factory MobileTask.fromJson(Map<String, dynamic> json) {
    final allowedActions = (json['allowed_actions'] as List? ?? const [])
        .map((action) => action.toString())
        .where((action) => action.isNotEmpty)
        .toSet();
    return MobileTask(
      id: (json['id'] as num).toInt(),
      reference:
          json['handover_number'] as String? ??
          json['reference'] as String? ??
          '',
      title: json['title'] as String? ?? 'Handover',
      status: json['status'] as String? ?? 'requested',
      purpose:
          json['handover_purpose'] as String? ??
          json['purpose'] as String? ??
          'temporary_use',
      itemCount: (json['item_count'] as num? ?? 0).toInt(),
      quantity: (json['total_quantity'] as num? ?? 0).toDouble(),
      source:
          json['source_storage_name'] as String? ??
          (json['source_storage'] as Map?)?['name'] as String?,
      destination:
          json['destination_storage_name'] as String? ??
          (json['destination_storage'] as Map?)?['name'] as String?,
      allowedActions: allowedActions,
      requiresAction: json.containsKey('allowed_actions')
          ? allowedActions.isNotEmpty
          : json['requires_action'] == true || json['requires_action'] == 1,
    );
  }
}

class HandoverLine {
  const HandoverLine({
    required this.id,
    required this.itemId,
    required this.name,
    required this.sku,
    required this.unit,
    required this.quantityIssued,
    required this.quantityReceived,
    required this.quantityUsed,
    required this.quantityReturned,
    required this.quantityHeld,
    this.barcode,
    this.imageUrl,
  });

  final int id;
  final int itemId;
  final String name;
  final String sku;
  final String? barcode;
  final String unit;
  final String? imageUrl;
  final double quantityIssued;
  final double quantityReceived;
  final double quantityUsed;
  final double quantityReturned;
  final double quantityHeld;

  factory HandoverLine.fromJson(Map<String, dynamic> json) => HandoverLine(
    id: (json['id'] as num).toInt(),
    itemId: (json['item_id'] as num).toInt(),
    name: json['name'] as String? ?? 'Item',
    sku: json['sku'] as String? ?? '',
    barcode: json['barcode'] as String?,
    unit: json['unit'] as String? ?? 'pcs',
    imageUrl: json['image_url'] as String?,
    quantityIssued: (json['quantity_issued'] as num? ?? 0).toDouble(),
    quantityReceived: (json['quantity_received'] as num? ?? 0).toDouble(),
    quantityUsed: (json['quantity_used'] as num? ?? 0).toDouble(),
    quantityReturned: (json['quantity_returned'] as num? ?? 0).toDouble(),
    quantityHeld: (json['quantity_held'] as num? ?? 0).toDouble(),
  );
}

class HandoverDetail {
  const HandoverDetail({
    required this.task,
    required this.lines,
    this.recipientName,
    this.issuerName,
    this.scheduledForDate,
    this.reviewDate,
    this.notes,
    this.reconciliations = const [],
  });

  final MobileTask task;
  final List<HandoverLine> lines;
  final String? recipientName;
  final String? issuerName;
  final String? scheduledForDate;
  final String? reviewDate;
  final String? notes;
  final List<HandoverReconciliation> reconciliations;

  factory HandoverDetail.fromJson(Map<String, dynamic> json) {
    final lines = ((json['lines'] as List?) ?? const [])
        .whereType<Map>()
        .map((entry) => HandoverLine.fromJson(Map<String, dynamic>.from(entry)))
        .toList();
    final summary = <String, dynamic>{
      ...json,
      'item_count': lines.length,
      'total_quantity': lines.fold<double>(
        0,
        (sum, line) => sum + line.quantityIssued,
      ),
    };
    return HandoverDetail(
      task: MobileTask.fromJson(summary),
      lines: lines,
      recipientName: (json['recipient'] as Map?)?['name'] as String?,
      issuerName: (json['issuer'] as Map?)?['name'] as String?,
      scheduledForDate: json['scheduled_for_date'] as String?,
      reviewDate: json['custody_review_date'] as String?,
      notes: json['notes'] as String?,
      reconciliations: ((json['reconciliations'] as List?) ?? const [])
          .whereType<Map>()
          .map(
            (entry) => HandoverReconciliation.fromJson(
              Map<String, dynamic>.from(entry),
            ),
          )
          .toList(),
    );
  }
}

class HandoverReconciliation {
  const HandoverReconciliation({
    required this.unit,
    required this.reasons,
    required this.returnedTotal,
    required this.difference,
    this.discrepancyNotes,
    this.varianceReason,
    this.varianceNotes,
  });

  final String unit;
  final Map<String, double> reasons;
  final double returnedTotal;
  final double difference;
  final String? discrepancyNotes;
  final String? varianceReason;
  final String? varianceNotes;

  factory HandoverReconciliation.fromJson(Map<String, dynamic> json) {
    final entries = json['entries'] as Map? ?? const {};
    return HandoverReconciliation(
      unit: json['unit'] as String? ?? 'pcs',
      reasons: {
        for (final entry in entries.entries)
          entry.key.toString():
              ((entry.value as Map?)?['quantity'] as num? ?? 0).toDouble(),
      },
      returnedTotal: (json['returned_total'] as num? ?? 0).toDouble(),
      difference: (json['difference_total'] as num? ?? 0).toDouble(),
      discrepancyNotes: json['discrepancy_notes'] as String?,
      varianceReason: json['variance_reason_code'] as String?,
      varianceNotes: json['variance_notes'] as String?,
    );
  }
}

class PendingDraft {
  const PendingDraft({
    required this.id,
    required this.type,
    required this.title,
    required this.lineCount,
    required this.createdAt,
    this.state = 'draft',
    this.message,
  });

  final String id;
  final String type;
  final String title;
  final int lineCount;
  final DateTime createdAt;
  final String state;
  final String? message;
}

class MobileOperation {
  const MobileOperation({
    required this.id,
    required this.clientOperationId,
    required this.type,
    required this.status,
    required this.createdAt,
    this.reference,
    this.message,
    this.completedAt,
  });

  final int id;
  final String clientOperationId;
  final String type;
  final String status;
  final String? reference;
  final String? message;
  final DateTime createdAt;
  final DateTime? completedAt;

  factory MobileOperation.fromJson(Map<String, dynamic> json) =>
      MobileOperation(
        id: (json['id'] as num).toInt(),
        clientOperationId: json['client_operation_id'] as String? ?? '',
        type: json['operation_type'] as String? ?? 'operation',
        status: json['status'] as String? ?? 'pending',
        reference: json['reference'] as String?,
        message: json['message'] as String?,
        createdAt:
            DateTime.tryParse(json['created_at'] as String? ?? '') ??
            DateTime.fromMillisecondsSinceEpoch(0),
        completedAt: DateTime.tryParse(json['completed_at'] as String? ?? ''),
      );
}

class MobileBootstrap {
  const MobileBootstrap({
    required this.userName,
    required this.storages,
    required this.items,
    required this.tasks,
    required this.capabilities,
    this.permissions = const {},
    this.recipients = const [],
    this.settings = const {},
  });

  final String userName;
  final List<StorageLocation> storages;
  final List<InventoryItem> items;
  final List<MobileTask> tasks;
  final Set<String> capabilities;
  final Set<String> permissions;
  final List<MobileRecipient> recipients;
  final Map<String, dynamic> settings;

  bool hasPermission(String permission) => permissions.contains(permission);

  bool hasCapability(String capability) => capabilities.contains(capability);

  bool get canViewItems => hasPermission('items.view') && storages.isNotEmpty;

  bool get canUseStock =>
      canViewItems &&
      hasPermission('movements.usage') &&
      hasCapability('usage');

  bool get canRestock =>
      canViewItems &&
      hasPermission('movements.restock') &&
      hasCapability('restock') &&
      settings['manual_restock_enabled'] == true;

  bool get canViewHandovers => hasPermission('handovers.view');

  bool get canCreateTemporaryHandover =>
      canViewItems &&
      (hasPermission('handovers.create') ||
          hasPermission('handovers.request')) &&
      hasCapability('handover');

  bool get canCreateTransfer =>
      canViewItems &&
      hasPermission('handovers.create') &&
      hasCapability('transfer');

  bool get canCreateCustody =>
      canViewItems &&
      hasPermission('handovers.create') &&
      hasCapability('custody');

  bool get canCreateAnyHandover =>
      canCreateTemporaryHandover || canCreateTransfer || canCreateCustody;

  bool canCreateHandoverPurpose(String purpose) => switch (purpose) {
    'storage_transfer' => canCreateTransfer,
    'staff_custody' => canCreateCustody,
    _ => canCreateTemporaryHandover,
  };

  bool get canReceiveHandovers =>
      canViewHandovers && hasPermission('handovers.close');

  bool get canApproveHandovers =>
      canViewHandovers && hasPermission('handovers.approve');

  bool get canReturnCustody =>
      canViewHandovers &&
      hasPermission('handovers.custody_return') &&
      hasCapability('custody');

  bool get hasScanOutAction => canUseStock || canCreateAnyHandover;

  bool get canScanIn => canReceiveHandovers || canApproveHandovers;

  StorageLocation? get defaultStorage {
    for (final storage in storages) {
      if (storage.isDefault) return storage;
    }
    return storages.isEmpty ? null : storages.first;
  }

  List<UsageReason> get usageReasons {
    final raw = settings['usage_reasons'];
    final reasons = raw is List
        ? raw
              .whereType<Map>()
              .map(
                (entry) =>
                    UsageReason.fromJson(Map<String, dynamic>.from(entry)),
              )
              .where((reason) => reason.active && reason.code.isNotEmpty)
              .toList()
        : <UsageReason>[];
    if (reasons.isEmpty) return UsageReason.defaults;
    reasons.sort((left, right) {
      final order = left.sortOrder.compareTo(right.sortOrder);
      return order != 0 ? order : left.label.compareTo(right.label);
    });
    return reasons;
  }

  bool get requireUsageProof => settings['require_usage_proof'] == true;
}

class CartLine {
  const CartLine({
    required this.item,
    required this.quantity,
    this.packageLabel = 'Pieces',
    this.packageMultiplier = 1,
    this.expectedBalance,
    this.reasonCode,
    this.customReason,
  });

  final InventoryItem item;
  final double quantity;
  final String packageLabel;
  final double packageMultiplier;
  final double? expectedBalance;
  final String? reasonCode;
  final String? customReason;

  double get pieceQuantity => quantity * packageMultiplier;

  CartLine copyWith({
    double? quantity,
    String? packageLabel,
    double? packageMultiplier,
    double? expectedBalance,
    String? reasonCode,
    bool clearReason = false,
    String? customReason,
    bool clearCustomReason = false,
  }) => CartLine(
    item: item,
    quantity: quantity ?? this.quantity,
    packageLabel: packageLabel ?? this.packageLabel,
    packageMultiplier: packageMultiplier ?? this.packageMultiplier,
    expectedBalance: expectedBalance ?? this.expectedBalance,
    reasonCode: clearReason ? null : reasonCode ?? this.reasonCode,
    customReason: clearCustomReason ? null : customReason ?? this.customReason,
  );
}

class OperationReceipt {
  const OperationReceipt({
    required this.reference,
    required this.status,
    this.message,
  });

  final String reference;
  final String status;
  final String? message;
}

class CustodyReturnLine {
  const CustodyReturnLine({
    required this.handoverLineId,
    this.serviceable = 0,
    this.damaged = 0,
    this.consumed = 0,
    this.lost = 0,
    this.notes,
  });

  final int handoverLineId;
  final double serviceable;
  final double damaged;
  final double consumed;
  final double lost;
  final String? notes;

  double get total => serviceable + damaged + consumed + lost;

  Map<String, dynamic> toJson() => {
    'handover_line_id': handoverLineId,
    'serviceable_quantity': serviceable,
    'damaged_quantity': damaged,
    'consumed_quantity': consumed,
    'lost_quantity': lost,
    'notes': notes,
  };
}
