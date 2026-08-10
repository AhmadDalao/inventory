class HandoverReconciliationMath {
  const HandoverReconciliationMath._();

  static double physicalUsed({
    required double received,
    required double returned,
  }) => (received - returned).clamp(0, double.infinity);

  static double operationalUsed(Map<String, double> reasons) {
    return (reasons['online'] ?? 0) -
        (reasons['noshow'] ?? 0) +
        (reasons['walkin'] ?? 0) +
        (reasons['event'] ?? 0) +
        (reasons['sport'] ?? 0) +
        (reasons['damage'] ?? 0) +
        (reasons['complimentary'] ?? 0) +
        (reasons['other'] ?? 0);
  }

  static double difference({
    required double physicalUsed,
    required Map<String, double> reasons,
  }) => physicalUsed - operationalUsed(reasons);

  static bool noShowIsValid(Map<String, double> reasons) {
    return (reasons['noshow'] ?? 0) <= (reasons['online'] ?? 0);
  }
}
