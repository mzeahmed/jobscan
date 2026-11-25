/** Niveau d'un score : sert à la couleur (excellent ≥ 75, moyen ≥ 50, faible en dessous). */
export function scoreLevel(score: number): 'excellent' | 'medium' | 'low' {
  if (score >= 75) {
    return 'excellent';
  }

  return score >= 50 ? 'medium' : 'low';
}
