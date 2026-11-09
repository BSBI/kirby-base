<?php

declare(strict_types=1);

namespace BSBI\WebBase\helpers;

use InvalidArgumentException;

/**
 * Makes passwords people can read out and type: three words from a list,
 * joined by one separator, e.g. `Allium-Acer-Arbutus` (bsbi-web#869).
 *
 * Words are picked with `random_int`, a cryptographically secure source. The
 * default list is plant genera; a site supplies its own through the
 * `memberPasswords.words` option (see {@see self::fromOptions()}).
 */
final readonly class MemberPasswordGenerator
{
    /** One of these joins all three words in a password. */
    public const array SEPARATORS = ['-', '_', '*'];

    /** @var list<string> */
    public const array DEFAULT_WORDS = [
        'Abelia', 'Abies', 'Acer', 'Aegle', 'Aesculus', 'Agrostis', 'Alchemilla', 'Allium',
        'Alnus', 'Alopecurus', 'Amelanchier', 'Anemone', 'Angelica', 'Anthoxanthum', 'Aquilegia',
        'Arabis', 'Arbutus', 'Arctium', 'Armeria', 'Armoracia', 'Artemisia', 'Asarum', 'Asclepias',
        'Aster', 'Astragalus', 'Athyrium', 'Atriplex', 'Aurinia', 'Azalea', 'Ballota', 'Bellis',
        'Betula', 'Bidens', 'Bistorta', 'Borago', 'Brachypodium', 'Brassica', 'Buxus', 'Calendula',
        'Calluna', 'Campanula', 'Cardamine', 'Carex', 'Carpinus', 'Castanea', 'Catananche',
        'Ceanothus', 'Centaurea', 'Cerastium', 'Cercis', 'Chaenomeles', 'Chamaecyparis', 'Chelidonium',
        'Chenopodium', 'Chrysanthemum', 'Cichorium', 'Cirsium', 'Clematis', 'Colchicum', 'Convolvulus',
        'Cornus', 'Corylus', 'Cotoneaster', 'Crataegus', 'Crocus', 'Cymbalaria', 'Cynara',
        'Cytisus', 'Dactylis', 'Daphne', 'Daucus', 'Delphinium', 'Deschampsia', 'Digitalis',
        'Dipsacus', 'Doronicum', 'Dryopteris', 'Echinops', 'Elymus', 'Epilobium', 'Equisetum',
        'Erica', 'Erodium', 'Eryngium', 'Erysimum', 'Euonymus', 'Eupatorium', 'Euphorbia',
        'Fagus', 'Festuca', 'Filipendula', 'Foeniculum', 'Forsythia', 'Fraxinus', 'Fritillaria',
        'Fuchsia', 'Galanthus', 'Galium', 'Geranium', 'Geum', 'Ginkgo', 'Glechoma', 'Glyceria',
        'Gypsophila', 'Hamamelis', 'Hedera', 'Helleborus', 'Helianthus', 'Hemerocallis', 'Heracleum',
        'Hieracium', 'Hippophae', 'Holcus', 'Hyacinthus', 'Hydrangea', 'Hypericum', 'Ilex',
        'Impatiens', 'Inula', 'Iris', 'Isolepis', 'Itea', 'Iva', 'Jasione', 'Juncus', 'Juniperus',
        'Knautia', 'Koeleria', 'Lactuca', 'Lamium', 'Lathyrus', 'Lavandula', 'Leontodon', 'Leucanthemum',
        'Ligustrum', 'Linaria', 'Linum', 'Lolium', 'Lotus', 'Luzula', 'Lychnis', 'Lysimachia',
        'Malus', 'Marrubium', 'Matricaria', 'Medicago', 'Melilotus', 'Mentha', 'Mertensia',
        'Milium', 'Mimulus', 'Morus', 'Myosotis', 'Narcissus', 'Nasturtium', 'Nepeta', 'Nigella',
        'Oenothera', 'Ononis', 'Origanum', 'Ornithogalum', 'Oxalis', 'Paeonia', 'Papaver',
        'Parthenocissus', 'Pastinaca', 'Pelargonium', 'Penstemon', 'Peucedanum', 'Phalaris',
        'Phlox', 'Phragmites', 'Picea', 'Pinus', 'Plantago', 'Platanus', 'Poa', 'Polygonum',
        'Populus', 'Potentilla', 'Primula', 'Prunella', 'Prunus', 'Pseudotsuga', 'Pulmonaria',
        'Quercus', 'Ranunculus', 'Raphanus', 'Rhinanthus', 'Ribes', 'Rosa', 'Rubus', 'Rumex',
        'Salix', 'Sambucus', 'Saponaria', 'Saxifraga', 'Scabiosa', 'Scirpus', 'Sedum',
    ];

    /** @var list<string> */
    private array $words;

    /**
     * @param array<array-key, string> $words The words to choose from
     * @throws InvalidArgumentException If the list is empty, or a word is under
     *                                  2 characters (three of them must reach
     *                                  Kirby's 8-character minimum)
     */
    public function __construct(array $words = self::DEFAULT_WORDS)
    {
        $words = array_values($words);
        if ($words === []) {
            throw new InvalidArgumentException('The password word list is empty.');
        }
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) {
                throw new InvalidArgumentException('Password words need at least 2 characters: "' . $word . '".');
            }
        }
        $this->words = $words;
    }

    /**
     * The generator for the site's `memberPasswords.words` option, or the
     * default list when the option isn't set.
     *
     * @param mixed $configured The option's value
     * @return self
     * @throws InvalidArgumentException If the option is set but unusable
     */
    public static function fromOptions(mixed $configured): self
    {
        if ($configured === null) {
            return new self();
        }
        if (!is_array($configured)) {
            throw new InvalidArgumentException('memberPasswords.words must be a list of words.');
        }
        return new self(array_map(static fn (mixed $word): string => is_string($word) ? $word : '', $configured));
    }

    /**
     * @return string A new password
     */
    public function generate(): string
    {
        $separator = self::SEPARATORS[random_int(0, count(self::SEPARATORS) - 1)];
        $picked = [];
        for ($i = 0; $i < 3; $i++) {
            $picked[] = $this->words[random_int(0, count($this->words) - 1)];
        }
        return implode($separator, $picked);
    }
}
