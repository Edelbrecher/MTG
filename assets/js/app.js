class MTGCardAPI {
    static async searchCard(cardName) {
        try {
            const url = `https://api.scryfall.com/cards/named?exact=${encodeURIComponent(cardName)}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error('Card not found');
            }
            
            const data = await response.json();
            return this.formatCardData(data);
        } catch (error) {
            console.error('Error fetching card:', error);
            return null;
        }
    }
    
    static async searchCardFuzzy(cardName) {
        try {
            const url = `https://api.scryfall.com/cards/named?fuzzy=${encodeURIComponent(cardName)}`;
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error('Card not found');
            }
            
            const data = await response.json();
            return this.formatCardData(data);
        } catch (error) {
            console.error('Error fetching card:', error);
            return null;
        }
    }
    
    static formatCardData(data) {
        return {
            name: data.name || '',
            mana_cost: data.mana_cost || '',
            cmc: data.cmc || 0,
            type_line: data.type_line || '',
            oracle_text: data.oracle_text || '',
            colors: data.colors || [],
            color_identity: data.color_identity || [],
            power: data.power || null,
            toughness: data.toughness || null,
            rarity: data.rarity || '',
            set_name: data.set_name || '',
            set: data.set || '',
            image_url: data.image_uris?.normal || ''
        };
    }
}

class MTGCardRenderer {
    static renderCard(cardData, quantity = 1, cardId = null) {
        const colors = cardData.colors || [];
        const borderClass = this.getBorderClass(colors);
        
        return `
            <div class="mtg-card" data-card-id="${cardId || ''}">
                <div class="mtg-card-border ${borderClass}"></div>
                <img src="${cardData.image_url || 'assets/images/card-back.jpg'}" 
                     alt="${cardData.name}" 
                     class="mtg-card-image"
                     onerror="this.src='assets/images/card-back.jpg'">
                <div class="mtg-card-content">
                    <div class="mtg-card-name">${cardData.name}</div>
                    <div class="mtg-card-cost">
                        ${this.renderManaCost(cardData.mana_cost)}
                    </div>
                    <div class="mtg-card-type">${cardData.type_line}</div>
                    ${cardData.power && cardData.toughness ? 
                        `<div class="text-muted" style="font-size: 0.75rem;">${cardData.power}/${cardData.toughness}</div>` 
                        : ''}
                    ${quantity > 1 ? 
                        `<div class="text-muted" style="font-size: 0.75rem;">Anzahl: ${quantity}</div>` 
                        : ''}
                </div>
            </div>
        `;
    }
    
    static getBorderClass(colors) {
        if (!colors || colors.length === 0) return 'colorless';
        if (colors.length > 1) return 'multicolor';
        return colors[0].toLowerCase();
    }
    
    static renderManaCost(manaCost) {
        if (!manaCost) return '';
        
        const symbols = manaCost.match(/\{[^}]+\}/g) || [];
        return symbols.map(symbol => {
            const cleanSymbol = symbol.replace(/[{}]/g, '');
            const cssClass = this.getManaSymbolClass(cleanSymbol);
            return `<span class="mana-symbol ${cssClass}">${cleanSymbol}</span>`;
        }).join('');
    }
    
    static getManaSymbolClass(symbol) {
        switch (symbol.toUpperCase()) {
            case 'W': return 'mana-w';
            case 'U': return 'mana-u';
            case 'B': return 'mana-b';
            case 'R': return 'mana-r';
            case 'G': return 'mana-g';
            default: return 'mana-c';
        }
    }
}

class CollectionManager {
    static async addCard(cardName, quantity = 1) {
        const card = await MTGCardAPI.searchCard(cardName);
        if (!card) {
            throw new Error('Card not found');
        }
        
        const formData = new FormData();
        formData.append('action', 'add_card');
        formData.append('card_name', cardName);
        formData.append('quantity', quantity);
        
        const response = await fetch('collection.php', {
            method: 'POST',
            body: formData
        });
        
        return response.ok;
    }
    
    static async updateQuantity(cardId, quantity) {
        const formData = new FormData();
        formData.append('action', 'update_quantity');
        formData.append('card_id', cardId);
        formData.append('quantity', quantity);
        
        const response = await fetch('collection.php', {
            method: 'POST',
            body: formData
        });
        
        return response.ok;
    }
    
    static async deleteCard(cardId) {
        const formData = new FormData();
        formData.append('action', 'delete_card');
        formData.append('card_id', cardId);
        
        const response = await fetch('collection.php', {
            method: 'POST',
            body: formData
        });
        
        return response.ok;
    }
}

class DeckBuilder {
    static analyzeColors(cards) {
        const colorCount = { W: 0, U: 0, B: 0, R: 0, G: 0 };
        
        cards.forEach(card => {
            if (card.card_data && card.card_data.colors) {
                card.card_data.colors.forEach(color => {
                    colorCount[color] = (colorCount[color] || 0) + card.quantity;
                });
            }
        });
        
        return colorCount;
    }
    
    static calculateManaCurve(cards) {
        const curve = { 0: 0, 1: 0, 2: 0, 3: 0, 4: 0, 5: 0, '6+': 0 };
        
        cards.forEach(card => {
            if (card.card_data && card.card_data.cmc !== undefined) {
                const cmc = parseInt(card.card_data.cmc);
                const key = cmc >= 6 ? '6+' : cmc.toString();
                curve[key] = (curve[key] || 0) + card.quantity;
            }
        });
        
        return curve;
    }
    
    static validateDeck(cards, format = 'Standard') {
        const issues = [];
        const totalCards = cards.reduce((sum, card) => sum + card.quantity, 0);
        
        // Check minimum deck size
        if (format === 'Commander') {
            if (totalCards !== 100) {
                issues.push(`Commander decks must have exactly 100 cards (current: ${totalCards})`);
            }
        } else {
            if (totalCards < 60) {
                issues.push(`Deck must have at least 60 cards (current: ${totalCards})`);
            }
        }
        
        // Check for illegal quantities
        cards.forEach(card => {
            if (card.quantity > 4 && !this.isBasicLand(card)) {
                issues.push(`Too many copies of ${card.card_name} (max 4, current: ${card.quantity})`);
            }
        });
        
        return {
            isValid: issues.length === 0,
            issues: issues
        };
    }
    
    static isBasicLand(card) {
        const basicLands = ['Plains', 'Island', 'Swamp', 'Mountain', 'Forest'];
        return basicLands.includes(card.card_name) || 
               (card.card_data && card.card_data.type_line && 
                card.card_data.type_line.includes('Basic Land'));
    }
}

// Utility functions
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem;
        background: ${type === 'success' ? 'var(--success-color)' : 'var(--danger-color)'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        z-index: 1000;
        animation: slideIn 0.3s ease-out;
    `;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-in';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOut {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);

// Export classes for use in other files
window.MTGCardAPI = MTGCardAPI;
window.MTGCardRenderer = MTGCardRenderer;
window.CollectionManager = CollectionManager;
window.DeckBuilder = DeckBuilder;
window.showNotification = showNotification;
window.debounce = debounce;
